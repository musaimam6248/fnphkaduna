<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $cleanPath = ltrim($path, '/');
    $baseUrl = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : '', '/');
    return ($baseUrl !== '' ? $baseUrl : '') . '/' . $cleanPath;
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}

function paginate(int $totalRows, int $perPage, int $page): array
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $currentPage = max(1, min($page, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'current_page' => $currentPage,
        'per_page' => $perPage,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'offset' => $offset,
    ];
}

function month_label(int $month): string
{
    $safeMonth = max(1, min(12, $month));
    $date = DateTime::createFromFormat('!m', (string) $safeMonth);
    return $date ? $date->format('F') : 'Unknown';
}

function upload_url(string $folder, ?string $filename): string
{
    if (!$filename) {
        return app_url('assets/img/placeholder.svg');
    }

    return app_url('uploads/' . trim($folder, '/') . '/' . rawurlencode($filename));
}

function normalize_text(string $input): string
{
    return trim(preg_replace('/\s+/', ' ', $input) ?? '');
}

function sanitize_rich_text_html(string $html): string
{
    $html = preg_replace('~<(script|style|iframe|object|svg)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? '';
    $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote><div>';
    $html = strip_tags($html, $allowedTags);
    $allowedNames = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'div'];

    $safeHtml = preg_replace_callback(
        '/<\s*(\/?)\s*([a-z0-9]+)\b[^>]*>/i',
        static function (array $match) use ($allowedNames): string {
            $tag = strtolower($match[2]);
            if (!in_array($tag, $allowedNames, true)) {
                return '';
            }
            return '<' . ($match[1] === '/' ? '/' : '') . $tag . '>';
        },
        $html
    );

    return trim(str_ireplace(['<div>', '</div>'], ['<p>', '</p>'], $safeHtml ?? ''));
}

function normalize_hex_color(string $input): ?string
{
    $color = trim($input);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : null;
}

function hex_color_with_alpha(?string $color, string $alpha = 'd9'): ?string
{
    $safeColor = $color !== null ? normalize_hex_color($color) : null;
    return $safeColor ? ($safeColor . $alpha) : null;
}

function format_month_year(int $month, int $year): string
{
    return month_label($month) . ' ' . $year;
}

function back_with_query(string $path, array $query = []): void
{
    $queryString = http_build_query($query);
    redirect($path . ($queryString ? ('?' . $queryString) : ''));
}

function format_datetime_human(?string $datetime): string
{
    if (!$datetime) {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($datetime))->format('d M Y, h:i A');
    } catch (Throwable $exception) {
        return 'Not set';
    }
}

function format_datetime_for_input(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }

    try {
        return (new DateTimeImmutable($datetime))->format('Y-m-d\TH:i');
    } catch (Throwable $exception) {
        return '';
    }
}

function get_submission_window_state(?string $opensAt, ?string $closesAt, bool $isEnabled): string
{
    if (!$isEnabled) {
        return 'disabled';
    }

    if (!$opensAt || !$closesAt) {
        return 'not_set';
    }

    try {
        $openTime = new DateTimeImmutable($opensAt);
        $closeTime = new DateTimeImmutable($closesAt);
        $now = new DateTimeImmutable('now');
    } catch (Throwable $exception) {
        return 'not_set';
    }

    if ($openTime > $closeTime) {
        return 'not_set';
    }

    if ($now < $openTime) {
        return 'upcoming';
    }

    if ($now > $closeTime) {
        return 'closed';
    }

    return 'open';
}

function get_submission_window_label(string $state): string
{
    return match ($state) {
        'open' => 'Open',
        'upcoming' => 'Upcoming',
        'closed' => 'Closed',
        'disabled' => 'Disabled',
        default => 'Not Set',
    };
}

function get_submission_window_badge_class(string $state): string
{
    return match ($state) {
        'open' => 'success',
        'upcoming' => 'warning',
        'closed', 'disabled' => 'danger',
        default => 'secondary',
    };
}
