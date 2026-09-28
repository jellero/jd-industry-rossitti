<?php
function input_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        ApiResponse::error('JSON non valido', 400);
    }
    return $data;
}

function str_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return (int)$value;
}

function date_or_null($value): ?string
{
    $value = str_or_null($value);
    if ($value === null) {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function dt_or_null($value): ?string
{
    $value = str_or_null($value);
    if ($value === null) {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function require_id($value, string $name = 'id'): int
{
    $id = (int)$value;
    if ($id <= 0) {
        ApiResponse::error("Parametro $name mancante o non valido", 400);
    }
    return $id;
}

function normalize_order_name(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        ApiResponse::error('Nome commessa vuoto', 400);
    }
    if (mb_strlen($value) > 80) {
        ApiResponse::error('Nome commessa troppo lungo: massimo 80 caratteri', 400);
    }
    return $value;
}
