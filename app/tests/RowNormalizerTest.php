<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\RowNormalizer;

function assertTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$phone = RowNormalizer::normalizePhone('380672341057.0');
assertTrue($phone === '380672341057', 'Phone normalization failed for xlsx float');

$budget = RowNormalizer::normalizeBudget('23 700');
assertTrue($budget === 23700, 'Budget with spaces should be 23700');

$scientific = RowNormalizer::normalizeBudget('1.2E+15');
assertTrue($scientific === 1200000000000000, 'Scientific notation should be accepted');

$excelDate = RowNormalizer::normalizeDate('45848.213125', true);
assertTrue($excelDate === '2025-07-10 05:06:54', 'Excel serial should be converted to datetime');

$row = RowNormalizer::normalizeRow(
    array('external_id', 'created_at', 'first_name', 'last_name', 'phone', 'email', 'city', 'source', 'utm_campaign', 'product', 'budget_uah', 'status', 'manager', 'comment', 'next_contact_at'),
    array('LD-000001', '2025-07-10 05:06:54', 'Олександр', 'Поліщук', '#ERROR!', 'oleksandr.polishchuk693@ukr.net', 'Івано-Франківськ', 'Facebook Ads', 'catalog_2026', 'Контекстна реклама', '23 700', 'in_progress', 'Литвиненко Н.', '', '2025-07-23 05:06:54')
);
assertTrue($row['phone'] === null, 'Phone should be invalid');
assertTrue(in_array('phone_invalid', explode(',', $row['warnings']), true), 'Phone warning should be present');

fwrite(STDOUT, "RowNormalizer checks passed\n");
