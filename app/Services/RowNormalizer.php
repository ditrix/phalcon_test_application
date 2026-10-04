<?php

namespace App\Services;

class RowNormalizer
{
    public static function normalizeRow(array $headers, array $values)
    {
        $row = array();
        foreach ($headers as $index => $header) {
            $row[trim((string) $header)] = isset($values[$index]) ? $values[$index] : '';
        }

        $warnings = array();
        $normalized = array(
            'external_id' => null,
            'created_at' => null,
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'email' => null,
            'city' => null,
            'source' => null,
            'utm_campaign' => null,
            'product' => null,
            'budget_uah' => null,
            'status' => null,
            'manager' => null,
            'comment' => null,
            'next_contact_at' => null,
            'warnings' => '',
        );

        foreach ($row as $field => $value) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = self::normalizeString($value);
            }
        }

        $externalId = self::normalizeString($row['external_id'] ?? '');
        if ($externalId === null) {
            throw new \InvalidArgumentException('external_id_missing');
        }
        $normalized['external_id'] = self::truncateValue($externalId, 32);

        foreach (array('first_name', 'last_name', 'city', 'source', 'utm_campaign', 'product', 'status', 'manager') as $field) {
            $value = self::normalizeString($row[$field] ?? '');
            if ($value !== null) {
                $normalized[$field] = self::truncateValue($value, self::getMaxLength($field));
            }
        }

        $phone = self::normalizePhone($row['phone'] ?? '');
        $normalized['phone'] = $phone;
        if ($phone === null && self::hasValue($row['phone'] ?? '')) {
            $warnings[] = 'phone_invalid';
        }

        $email = self::normalizeEmail($row['email'] ?? '');
        $normalized['email'] = $email;
        if ($email === null && self::hasValue($row['email'] ?? '')) {
            $warnings[] = 'email_invalid';
        }

        $budget = self::normalizeBudget($row['budget_uah'] ?? '');
        $normalized['budget_uah'] = $budget;
        if ($budget === null && self::hasValue($row['budget_uah'] ?? '')) {
            $warnings[] = 'budget_invalid';
        }

        $createdAt = self::normalizeDate($row['created_at'] ?? '', true);
        $normalized['created_at'] = $createdAt;

        $nextContact = self::normalizeDate($row['next_contact_at'] ?? '', false);
        $normalized['next_contact_at'] = $nextContact;
        if ($nextContact === null && self::hasValue($row['next_contact_at'] ?? '')) {
            $warnings[] = 'date_invalid';
        }

        $normalized['comment'] = self::normalizeString($row['comment'] ?? '');
        if ($normalized['comment'] !== null) {
            $normalized['comment'] = self::truncateValue($normalized['comment'], 65535);
        }

        if (isset($row['status']) && self::normalizeString($row['status'] ?? '') !== null) {
            $normalized['status'] = self::truncateValue((string) self::normalizeString($row['status'] ?? ''), 50);
        }

        $normalized['warnings'] = implode(',', array_unique($warnings));

        return $normalized;
    }

    public static function normalizeString($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            $value = (string) $value;
        }

        $string = trim((string) $value);
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        if ($string === '') {
            return null;
        }

        return $string;
    }

    public static function normalizePhone($value)
    {
        $clean = self::normalizeString($value);
        if ($clean === null) {
            return null;
        }

        if (is_numeric($clean)) {
            $clean = rtrim(rtrim((string) ((float) $clean), '0'), '.');
        }

        $digits = preg_replace('/\D+/', '', $clean);
        if ($digits === '' || strlen($digits) !== 12 || strpos($digits, '380') !== 0) {
            return null;
        }

        return $digits;
    }

    public static function normalizeEmail($value)
    {
        $email = self::normalizeString($value);
        if ($email === null) {
            return null;
        }

        $email = strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    public static function normalizeBudget($value)
    {
        $clean = self::normalizeString($value);
        if ($clean === null) {
            return null;
        }

        $clean = str_replace(array("\xC2\xA0", ' '), '', $clean);
        if ($clean === '') {
            return null;
        }

        $numeric = str_replace(',', '', $clean);
        if ($numeric === '' || !is_numeric($numeric)) {
            return null;
        }

        $number = (float) $numeric;
        if (!is_finite($number) || $number < 0 || floor($number) != $number) {
            return null;
        }

        $limit = 18446744073709551615;
        if ($number > $limit) {
            return null;
        }

        return (int) $number;
    }

    public static function normalizeDate($value, $required)
    {
        $text = self::normalizeString($value);
        if ($text === null) {
            if ($required) {
                throw new \InvalidArgumentException('created_at_missing');
            }
            return null;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $text)) {
            $ts = (int) round(((float) $text - 25569) * 86400);
            return gmdate('Y-m-d H:i:s', $ts);
        }

        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $text);
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }

        if ($required) {
            throw new \InvalidArgumentException('created_at_invalid');
        }

        return null;
    }

    public static function truncateValue($value, $length)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr((string) $value, 0, (int) $length, 'UTF-8');
        }

        return substr((string) $value, 0, (int) $length);
    }

    public static function hasValue($value)
    {
        return self::normalizeString($value) !== null;
    }

    private static function getMaxLength($field)
    {
        $map = array(
            'external_id' => 32,
            'first_name' => 100,
            'last_name' => 100,
            'city' => 100,
            'source' => 100,
            'utm_campaign' => 100,
            'product' => 100,
            'status' => 50,
            'manager' => 100,
            'email' => 150,
            'phone' => 20,
        );

        return isset($map[$field]) ? $map[$field] : 255;
    }
}
