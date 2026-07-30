<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
requireAuthApi();

$action = $_GET['action'] ?? 'overview';
$db = getDB();

switch ($action) {

    // ── OVERVIEW STATS ────────────────────────────────────────────────
    case 'overview':
        $stats = [];

        // Total subscribers by status
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM subscribers GROUP BY status");
        $sub_stats = [];
        foreach ($stmt->fetchAll() as $row) {
            $sub_stats[$row['status']] = (int)$row['count'];
        }
        $stats['subscribers'] = $sub_stats;
        $stats['total_subscribers'] = array_sum($sub_stats);

        // Total campaigns
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM campaigns GROUP BY status");
        $camp_stats = [];
        foreach ($stmt->fetchAll() as $row) {
            $camp_stats[$row['status']] = (int)$row['count'];
        }
        $stats['campaigns'] = $camp_stats;

        // Total opens today
        $stmt = $db->query("SELECT COUNT(*) as count FROM campaign_views WHERE created_at >= NOW() - INTERVAL '24 hours'");
        $stats['opens_today'] = (int)$stmt->fetch()['count'];

        // Total clicks today
        $stmt = $db->query("SELECT COUNT(*) as count FROM link_clicks WHERE created_at >= NOW() - INTERVAL '24 hours'");
        $stats['clicks_today'] = (int)$stmt->fetch()['count'];

        // Total opens all time
        $stmt = $db->query("SELECT COUNT(*) as count FROM campaign_views");
        $stats['total_opens'] = (int)$stmt->fetch()['count'];

        // Total clicks all time
        $stmt = $db->query("SELECT COUNT(*) as count FROM link_clicks");
        $stats['total_clicks'] = (int)$stmt->fetch()['count'];

        // Total bounces
        $stmt = $db->query("SELECT COUNT(*) as count FROM subscribers WHERE status='blocklisted'");
        $stats['total_blocklisted'] = (int)$stmt->fetch()['count'];

        // Lists count
        $stmt = $db->query("SELECT COUNT(*) as count FROM lists");
        $stats['total_lists'] = (int)$stmt->fetch()['count'];

        echo json_encode($stats);
        break;

    // ── ALL CAMPAIGNS ─────────────────────────────────────────────────
    case 'campaigns':
        $stmt = $db->query("
            SELECT
                c.id,
                c.name,
                c.subject,
                c.status,
                c.type,
                c.created_at,
                c.started_at,
                c.updated_at,
                (SELECT COUNT(DISTINCT cv.subscriber_id) FROM campaign_views cv WHERE cv.campaign_id = c.id) as unique_opens,
                (SELECT COUNT(*) FROM campaign_views cv WHERE cv.campaign_id = c.id) as total_opens,
                (SELECT COUNT(DISTINCT lc.subscriber_id) FROM link_clicks lc WHERE lc.campaign_id = c.id) as unique_clicks,
                (SELECT COUNT(*) FROM link_clicks lc WHERE lc.campaign_id = c.id) as total_clicks,
                (SELECT COUNT(DISTINCT sl.subscriber_id)
                 FROM subscriber_lists sl
                 JOIN campaign_lists cl ON sl.list_id = cl.list_id
                 WHERE cl.campaign_id = c.id) as total_recipients
            FROM campaigns c
            ORDER BY c.created_at DESC
        ");
        $campaigns = $stmt->fetchAll();
        foreach ($campaigns as &$c) {
            $c['open_rate'] = $c['total_recipients'] > 0
                ? round($c['unique_opens'] / $c['total_recipients'] * 100, 1) : 0;
            $c['click_rate'] = $c['total_recipients'] > 0
                ? round($c['unique_clicks'] / $c['total_recipients'] * 100, 1) : 0;
        }
        echo json_encode($campaigns);
        break;

    // ── SINGLE CAMPAIGN DETAIL ────────────────────────────────────────
    case 'campaign_detail':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'No campaign ID']); break; }

        // Campaign info
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch();

        // Subscriber tracking
        $stmt = $db->prepare("
            SELECT
                s.id,
                s.email,
                s.name,
                s.status,
                MIN(cv.created_at) as first_open,
                COUNT(cv.id) as open_count,
                COUNT(lc.id) as click_count,
                MIN(lc.created_at) as first_click
            FROM subscribers s
            JOIN subscriber_lists sl ON s.id = sl.subscriber_id
            JOIN campaign_lists cl ON sl.list_id = cl.list_id AND cl.campaign_id = ?
            LEFT JOIN campaign_views cv ON cv.subscriber_id = s.id AND cv.campaign_id = ?
            LEFT JOIN link_clicks lc ON lc.subscriber_id = s.id AND lc.campaign_id = ?
            GROUP BY s.id, s.email, s.name, s.status
            ORDER BY first_open ASC NULLS LAST
        ");
        $stmt->execute([$id, $id, $id]);
        $subscribers = $stmt->fetchAll();

        // Opens over time (hourly)
        $stmt = $db->prepare("
            SELECT
                DATE_TRUNC('hour', created_at) as hour,
                COUNT(*) as opens
            FROM campaign_views
            WHERE campaign_id = ?
            GROUP BY hour
            ORDER BY hour ASC
        ");
        $stmt->execute([$id]);
        $opens_timeline = $stmt->fetchAll();

        // Top clicked links
        $stmt = $db->prepare("
            SELECT
                l.url,
                COUNT(lc.id) as clicks,
                COUNT(DISTINCT lc.subscriber_id) as unique_clicks
            FROM link_clicks lc
            JOIN links l ON lc.link_id = l.id
            WHERE lc.campaign_id = ?
            GROUP BY l.url
            ORDER BY clicks DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $top_links = $stmt->fetchAll();

        echo json_encode([
            'campaign' => $campaign,
            'subscribers' => $subscribers,
            'opens_timeline' => $opens_timeline,
            'top_links' => $top_links,
            'summary' => [
                'total' => count($subscribers),
                'opened' => count(array_filter($subscribers, fn($s) => $s['first_open'])),
                'clicked' => count(array_filter($subscribers, fn($s) => $s['click_count'] > 0)),
                'not_opened' => count(array_filter($subscribers, fn($s) => !$s['first_open']))
            ]
        ]);
        break;

    // ── REAL-TIME LIVE FEED ───────────────────────────────────────────
    case 'live_feed':
        $stmt = $db->query("
            SELECT
                'open' as type,
                s.email,
                s.name,
                c.name as campaign,
                cv.created_at as time
            FROM campaign_views cv
            JOIN subscribers s ON cv.subscriber_id = s.id
            JOIN campaigns c ON cv.campaign_id = c.id
            WHERE cv.created_at >= NOW() - INTERVAL '1 hour'
            UNION ALL
            SELECT
                'click' as type,
                s.email,
                s.name,
                c.name as campaign,
                lc.created_at as time
            FROM link_clicks lc
            JOIN subscribers s ON lc.subscriber_id = s.id
            JOIN campaigns c ON lc.campaign_id = c.id
            WHERE lc.created_at >= NOW() - INTERVAL '1 hour'
            ORDER BY time DESC
            LIMIT 50
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // ── SUBSCRIBER DETAIL ─────────────────────────────────────────────
    case 'subscriber':
        $email = $_GET['email'] ?? '';
        if (!$email) { echo json_encode(['error' => 'No email']); break; }

        $stmt = $db->prepare("SELECT * FROM subscribers WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        $subscriber = $stmt->fetch();
        if (!$subscriber) { echo json_encode(['error' => 'Not found']); break; }

        // Campaign history
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.name,
                c.subject,
                c.started_at,
                MIN(cv.created_at) as opened_at,
                COUNT(cv.id) as open_count,
                COUNT(lc.id) as click_count
            FROM campaigns c
            JOIN campaign_lists cl ON c.id = cl.campaign_id
            JOIN subscriber_lists sl ON cl.list_id = sl.list_id AND sl.subscriber_id = ?
            LEFT JOIN campaign_views cv ON cv.campaign_id = c.id AND cv.subscriber_id = ?
            LEFT JOIN link_clicks lc ON lc.campaign_id = c.id AND lc.subscriber_id = ?
            GROUP BY c.id, c.name, c.subject, c.started_at
            ORDER BY c.started_at DESC
        ");
        $stmt->execute([$subscriber['id'], $subscriber['id'], $subscriber['id']]);
        $history = $stmt->fetchAll();

        // Lists
        $stmt = $db->prepare("
            SELECT l.name, sl.status, sl.created_at
            FROM subscriber_lists sl
            JOIN lists l ON sl.list_id = l.id
            WHERE sl.subscriber_id = ?
        ");
        $stmt->execute([$subscriber['id']]);
        $lists = $stmt->fetchAll();

        echo json_encode([
            'subscriber' => $subscriber,
            'history' => $history,
            'lists' => $lists,
            'engagement_score' => calculateEngagementScore($history)
        ]);
        break;

    // ── OPEN RATE TRENDS ─────────────────────────────────────────────
    case 'trends':
        $stmt = $db->query("
            SELECT
                DATE_TRUNC('day', cv.created_at) as day,
                COUNT(DISTINCT cv.campaign_id) as campaigns,
                COUNT(*) as opens,
                COUNT(DISTINCT cv.subscriber_id) as unique_openers
            FROM campaign_views cv
            WHERE cv.created_at >= NOW() - INTERVAL '30 days'
            GROUP BY day
            ORDER BY day ASC
        ");
        $opens = $stmt->fetchAll();

        $stmt = $db->query("
            SELECT
                DATE_TRUNC('day', created_at) as day,
                COUNT(*) as clicks
            FROM link_clicks
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY day
            ORDER BY day ASC
        ");
        $clicks = $stmt->fetchAll();

        // Subscriber growth
        $stmt = $db->query("
            SELECT
                DATE_TRUNC('day', created_at) as day,
                COUNT(*) as new_subscribers
            FROM subscribers
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY day
            ORDER BY day ASC
        ");
        $growth = $stmt->fetchAll();

        echo json_encode([
            'opens' => $opens,
            'clicks' => $clicks,
            'growth' => $growth
        ]);
        break;

    // ── BEST SENDING TIMES ────────────────────────────────────────────
    case 'best_times':
        $stmt = $db->query("
            SELECT
                EXTRACT(DOW FROM created_at) as day_of_week,
                EXTRACT(HOUR FROM created_at) as hour,
                COUNT(*) as opens
            FROM campaign_views
            GROUP BY day_of_week, hour
            ORDER BY opens DESC
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // ── LIST ANALYTICS ────────────────────────────────────────────────
    case 'lists':
        $stmt = $db->query("
            SELECT
                l.id,
                l.name,
                l.type,
                l.created_at,
                COUNT(CASE WHEN s.status='enabled' THEN 1 END) as active,
                COUNT(CASE WHEN s.status='blocklisted' THEN 1 END) as blocklisted,
                COUNT(CASE WHEN s.status='disabled' THEN 1 END) as disabled,
                COUNT(sl.subscriber_id) as total
            FROM lists l
            LEFT JOIN subscriber_lists sl ON l.id = sl.list_id
            LEFT JOIN subscribers s ON sl.subscriber_id = s.id
            GROUP BY l.id, l.name, l.type, l.created_at
            ORDER BY total DESC
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // ── DOMAIN ANALYSIS ──────────────────────────────────────────────
    case 'domains':
        $stmt = $db->query("
            SELECT
                split_part(email, '@', 2) as domain,
                COUNT(*) as total,
                COUNT(CASE WHEN status='enabled' THEN 1 END) as active,
                COUNT(CASE WHEN status='blocklisted' THEN 1 END) as blocklisted
            FROM subscribers
            GROUP BY domain
            ORDER BY total DESC
            LIMIT 20
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // ── EXPORT CSV ───────────────────────────────────────────────────
    case 'export':
        $campaign_id = (int)($_GET['campaign_id'] ?? 0);
        if (!$campaign_id) { echo json_encode(['error' => 'No campaign ID']); break; }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="campaign_' . $campaign_id . '_tracking.csv"');

        $stmt = $db->prepare("
            SELECT
                s.email,
                s.name,
                CASE WHEN cv.created_at IS NOT NULL THEN 'Yes' ELSE 'No' END as opened,
                cv.created_at as opened_at,
                COALESCE(lc_count.clicks, 0) as clicks
            FROM subscribers s
            JOIN subscriber_lists sl ON s.id = sl.subscriber_id
            JOIN campaign_lists cl ON sl.list_id = cl.list_id AND cl.campaign_id = ?
            LEFT JOIN (
                SELECT subscriber_id, MIN(created_at) as created_at
                FROM campaign_views WHERE campaign_id = ? GROUP BY subscriber_id
            ) cv ON s.id = cv.subscriber_id
            LEFT JOIN (
                SELECT subscriber_id, COUNT(*) as clicks
                FROM link_clicks WHERE campaign_id = ? GROUP BY subscriber_id
            ) lc_count ON s.id = lc_count.subscriber_id
            ORDER BY cv.created_at ASC NULLS LAST
        ");
        $stmt->execute([$campaign_id, $campaign_id, $campaign_id]);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Email', 'Name', 'Opened', 'Opened At', 'Clicks']);
        while ($row = $stmt->fetch()) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function calculateEngagementScore($history) {
    if (empty($history)) return 0;
    $score = 0;
    foreach ($history as $h) {
        if ($h['opened_at']) $score += 10;
        $score += min($h['click_count'] * 15, 30);
    }
    return min(round($score / count($history)), 100);
}
