<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * CA-сертификаты и TLS.
 *
 * Хранение и валидация PEM, разрешение пути CA, сборка опций DSN. Реплика по умолчанию переиспользует CA основного узла.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_ssl_ca_relative(): string
{
    return 'ssl/replica-ca.pem';
}

function db_ssl_data_dir(): string
{
    return dirname(DB_INCLUDES_DIR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ssl';
}

function db_ssl_ca_absolute(?string $relative = null): string
{
    $rel = $relative !== null && $relative !== '' ? $relative : db_ssl_ca_relative();
    $rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
    if (str_contains($rel, '..')) {
        throw new InvalidArgumentException('Недопустимый путь к CA-сертификату');
    }
    return dirname(DB_INCLUDES_DIR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $rel;
}

function db_ssl_validate_pem(string $pem): bool
{
    $pem = trim($pem);
    if ($pem === '') {
        return false;
    }
    return str_contains($pem, '-----BEGIN CERTIFICATE-----')
        && str_contains($pem, '-----END CERTIFICATE-----');
}

function db_ssl_ca_resolve(?string $configured, bool $allowReplicaDefault = true): ?string
{
    if ($configured !== null && $configured !== '') {
        $path = db_ssl_ca_absolute($configured);
        return is_readable($path) ? $path : null;
    }
    if ($configured === '' && !$allowReplicaDefault) {
        return null;
    }
    if (!$allowReplicaDefault) {
        return null;
    }
    $default = db_ssl_ca_absolute(db_ssl_ca_relative());
    return is_readable($default) ? $default : null;
}

function db_ssl_ca_save(string $pem, ?string $relative = null): string
{
    if (!db_ssl_validate_pem($pem)) {
        throw new InvalidArgumentException('Файл должен содержать PEM-сертификат (-----BEGIN CERTIFICATE-----)');
    }
    $rel = $relative !== null && $relative !== '' ? $relative : db_ssl_ca_relative();
    $dir = db_ssl_data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог data/ssl/');
    }
    $path = db_ssl_ca_absolute($rel);
    if (file_put_contents($path, trim($pem) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить CA-сертификат — проверьте права data/ssl/');
    }
    @chmod($path, 0640);
    return $rel;
}

function db_ssl_ca_remove(?string $relative = null): void
{
    $path = db_ssl_ca_absolute($relative);
    if (is_file($path)) {
        @unlink($path);
    }
}

function db_ssl_ca_info(?string $configured = null, bool $allowReplicaDefault = true): array
{
    $path = db_ssl_ca_resolve($configured, $allowReplicaDefault);
    if ($path === null) {
        return ['installed' => false, 'path' => '', 'relative' => ''];
    }
    $info = [
        'installed' => true,
        'path' => $path,
        'relative' => $configured !== null && $configured !== '' ? $configured : db_ssl_ca_relative(),
        'size' => (int)@filesize($path),
        'modified' => (int)@filemtime($path),
    ];
    $pem = @file_get_contents($path);
    if (is_string($pem) && db_ssl_validate_pem($pem) && function_exists('openssl_x509_parse')) {
        $blocks = preg_split('/(?=-----BEGIN CERTIFICATE-----)/', $pem) ?: [];
        $info['cert_count'] = 0;
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $x509 = @openssl_x509_parse($block);
            if (is_array($x509)) {
                $info['cert_count']++;
                if (empty($info['subject'])) {
                    $info['subject'] = (string)($x509['name'] ?? '');
                }
            }
        }
    }
    return $info;
}

function db_pdo_ssl_opts(array $ep): array
{
    $opts = [];
    if (empty($ep['ssl'])) {
        return $opts;
    }
    $verify = !empty($ep['ssl_verify']);
    $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;

    $allowDefault = ($ep['ssl_ca_default'] ?? true);
    $configured = array_key_exists('ssl_ca', $ep) ? (string)$ep['ssl_ca'] : null;
    $caPath = db_ssl_ca_resolve($configured, $allowDefault);
    if ($caPath !== null) {
        $opts[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    } elseif ($verify) {
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $sysCa) {
            if (is_readable($sysCa)) {
                $opts[PDO::MYSQL_ATTR_SSL_CA] = $sysCa;
                break;
            }
        }
    }
    return $opts;
}
