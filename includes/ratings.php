<?php
declare(strict_types=1);

function ratingAverageValue($average, int $count): ?float
{
    if ($count < 1 || $average === null || $average === '') {
        return null;
    }

    return max(1.0, min(5.0, round((float)$average, 1)));
}

function ratingFormatAverage($average, int $count): string
{
    $value = ratingAverageValue($average, $count);

    if ($value === null) {
        return '-';
    }

    return number_format($value, 1, ',', '.');
}

function renderRatingSummary($average, int $count, bool $showCount = false, string $emptyLabel = ''): string
{
    $value = ratingAverageValue($average, $count);

    if ($value === null) {
        if ($emptyLabel === '') {
            return '';
        }

        return '<span class="rating-summary rating-summary-empty">'
            . '<i class="fa-regular fa-star" aria-hidden="true"></i>'
            . '<span>' . htmlspecialchars($emptyLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</span>';
    }

    $label = $count === 1 ? '1 değerlendirme' : number_format($count, 0, ',', '.') . ' değerlendirme';
    $html = '<span class="rating-summary" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fa-solid fa-star" aria-hidden="true"></i>'
        . '<span class="rating-score">' . htmlspecialchars(ratingFormatAverage($value, $count), ENT_QUOTES, 'UTF-8') . '</span>';

    if ($showCount) {
        $html .= '<span class="rating-count">(' . number_format($count, 0, ',', '.') . ')</span>';
    }

    return $html . '</span>';
}

function renderRatingStars(int $rating, bool $showValue = true): string
{
    $rating = max(1, min(5, $rating));
    $label = $rating . '/5';
    $html = '<span class="rating-stars" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';

    for ($star = 1; $star <= 5; $star += 1) {
        $iconClass = $star <= $rating ? 'fa-solid' : 'fa-regular';
        $html .= '<i class="' . $iconClass . ' fa-star" aria-hidden="true"></i>';
    }

    $html .= '</span>';

    if ($showValue) {
        $html .= '<span class="rating-value">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return '<span class="rating-stars-wrap">' . $html . '</span>';
}
