import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { PanelSection, PanelSectionRow, TextField, ToggleField, ButtonItem, Dropdown } from "@decky/ui";
import {
  loadSettings,
  saveSettings,
  Settings,
  WEBDAV_PROVIDERS,
  WebDAVProviderType,
  S3_PROVIDERS,
  S3ProviderType,
} from "../utils/Settings";
import { Hint, StatusMessage } from "./ui";
import type { Tone } from "./ui";
import { colors, actionRow, card } from "../utils/theme";

export function Settings() {
  const [settings, setSettings] = useState<Settings>(loadSettings());
  const [testing, setTesting] = useState<boolean>(false);
  const [testResult, setTestResult] = useState<{ tone: Tone; text: string } | null>(null);
  const [newPath, setNewPath] = useState<string>("");
  const [validating, setValidating] = useState<boolean>(false);
  const [pathValidationResult, setPathValidationResult] = useState<string | null>(null);

  const update = (patch: Partial<Settings>) => setSettings((prev) => ({ ...prev, ...patch }));

  useEffect(() => {
    const loadStorageConfig = async () => {
      try {
        const result: any = await call("load_storage_config", {});
        if (result.success && result.config) {
          const config = result.config;
          setSettings((prev) => ({
            ...prev,
            storageProvider: ["s3", "webdav", "ftp", "sftp"].includes(config.provider) ? config.provider : "webdav",
            webdavProvider:
              config.webdav_provider && ["custom", "nextcloud", "yandex", "box", "owncloud"].includes(config.webdav_provider)
                ? (config.webdav_provider as WebDAVProviderType)
                : prev.webdavProvider || "custom",
            webdavUrl: config.url || prev.webdavUrl || "",
            webdavUsername: config.username || prev.webdavUsername || "",
            webdavPassword: config.password || prev.webdavPassword || "",
            webdavOAuthToken: config.oauth_token || prev.webdavOAuthToken || "",
            s3Provider:
              config.s3_provider &&
              ["custom", "yandex", "vk", "cloudru", "aws", "backblaze", "wasabi", "digitalocean"].includes(config.s3_provider)
                ? (config.s3_provider as S3ProviderType)
                : prev.s3Provider || "custom",
            s3Endpoint: config.endpoint || prev.s3Endpoint || "",
            s3Region: config.region || prev.s3Region || "us-east-1",
            s3Bucket: config.bucket || prev.s3Bucket || "",
            s3AccessKey: config.access_key || prev.s3AccessKey || "",
            s3SecretKey: config.secret_key || prev.s3SecretKey || "",
            s3PathStyle: typeof config.path_style === "boolean" ? config.path_style : prev.s3PathStyle ?? false,
            s3SignatureVersion: config.signature_version || prev.s3SignatureVersion || "s3v4",
            ftpHost: config.provider === "ftp" ? config.host || "" : prev.ftpHost || "",
            ftpPort: config.provider === "ftp" ? String(config.port ?? prev.ftpPort ?? "21") : prev.ftpPort || "21",
            ftpUsername: config.provider === "ftp" ? config.username || "" : prev.ftpUsername || "",
            ftpPassword: config.provider === "ftp" ? config.password || "" : prev.ftpPassword || "",
            ftpUseTls: config.provider === "ftp" ? !!config.use_tls : prev.ftpUseTls ?? false,
            ftpPassive: config.provider === "ftp" ? config.passive ?? true : prev.ftpPassive ?? true,
            sftpHost: config.provider === "sftp" ? config.host || "" : prev.sftpHost || "",
            sftpPort: config.provider === "sftp" ? String(config.port ?? prev.sftpPort ?? "22") : prev.sftpPort || "22",
            sftpUsername: config.provider === "sftp" ? config.username || "" : prev.sftpUsername || "",
            sftpPassword: config.provider === "sftp" ? config.password || "" : prev.sftpPassword || "",
            sftpKeyPath: config.provider === "sftp" ? config.key_path || "" : prev.sftpKeyPath || "",
            sftpKeyPassphrase: config.provider === "sftp" ? config.key_passphrase || "" : prev.sftpKeyPassphrase || "",
          }));
        }
      } catch (error) {
        console.error("Error loading storage config:", error);
      }
    };
    loadStorageConfig();
  }, []);

  useEffect(() => {
    saveSettings(settings);
  }, [settings]);

  const buildPayload = (s: Settings): any => {
    const provider = s.storageProvider;
    if (provider === "webdav") {
      return {
        provider,
        url: s.webdavUrl,
        username: s.webdavUsername,
        password: s.webdavPassword,
        oauth_token: s.webdavOAuthToken,
        webdav_provider: s.webdavProvider,
      };
    }
    if (provider === "s3") {
      return {
        provider,
        s3_provider: s.s3Provider,
        endpoint: s.s3Endpoint,
        region: s.s3Region,
        bucket: s.s3Bucket,
        access_key: s.s3AccessKey,
        secret_key: s.s3SecretKey,
        path_style: s.s3PathStyle,
        signature_version: s.s3SignatureVersion,
      };
    }
    if (provider === "ftp") {
      return {
        provider,
        host: s.ftpHost,
        port: s.ftpPort,
        username: s.ftpUsername,
        password: s.ftpPassword,
        use_tls: s.ftpUseTls,
        passive: s.ftpPassive,
      };
    }
    return {
      provider,
      host: s.sftpHost,
      port: s.sftpPort,
      username: s.sftpUsername,
      password: s.sftpPassword,
      key_path: s.sftpKeyPath,
      key_passphrase: s.sftpKeyPassphrase,
    };
  };

  const saveStorageConfig = async (customSettings?: Settings) => {
    try {
      const result: any = await call("save_storage_config", buildPayload(customSettings || settings));
      setTestResult(
        result.success
          ? { tone: "success", text: "Настройки сохранены" }
          : { tone: "error", text: result.error || "Ошибка сохранения" }
      );
    } catch (error: any) {
      setTestResult({ tone: "error", text: error?.message || String(error) });
    }
  };

  const testConnection = async () => {
    setTesting(true);
    setTestResult(null);
    try {
      const result: any = await call("test_storage_connection", buildPayload(settings));
      setTestResult(
        result.success
          ? { tone: "success", text: result.message || "Подключение успешно" }
          : { tone: "error", text: result.error || result.message || "Ошибка подключения" }
      );
    } catch (error: any) {
      setTestResult({ tone: "error", text: error?.message || String(error) });
    } finally {
      setTesting(false);
    }
  };

  const addDefaultPath = async () => {
    if (!newPath.trim()) {
      setPathValidationResult("Введите путь");
      return;
    }
    setValidating(true);
    setPathValidationResult(null);
    try {
      const result: any = await call("validate_save_path", { path: newPath.trim() });
      if (result.success && result.path) {
        if (settings.defaultSavePaths.includes(result.path)) {
          setPathValidationResult("Этот путь уже добавлен");
        } else {
          update({ defaultSavePaths: [...settings.defaultSavePaths, result.path] });
          setNewPath("");
        }
      } else {
        setPathValidationResult(`✗ ${result.error || "Ошибка валидации"}`);
      }
    } catch (error) {
      setPathValidationResult(`✗ Ошибка: ${error}`);
    } finally {
      setValidating(false);
    }
  };

  const removeDefaultPath = (index: number) => {
    update({ defaultSavePaths: settings.defaultSavePaths.filter((_, i) => i !== index) });
  };

  const storageOptions = [
    { data: "webdav", label: "WebDAV" },
    { data: "s3", label: "S3 (Object Storage)" },
    { data: "ftp", label: "FTP / FTPS" },
    { data: "sftp", label: "SFTP" },
  ];

  return (
    <div>
      <PanelSection title="Хранилище">
        <PanelSectionRow>
          <Dropdown
            menuLabel="Тип хранилища"
            rgOptions={storageOptions}
            selectedOption={settings.storageProvider}
            onChange={(opt) => {
              const next: Settings = { ...settings, storageProvider: opt.data };
              setSettings(next);
              setTimeout(() => saveStorageConfig(next), 100);
            }}
          />
        </PanelSectionRow>
      </PanelSection>

      {settings.storageProvider === "webdav" && (
        <PanelSection title="WebDAV">
          <PanelSectionRow>
            <Dropdown
              menuLabel="Провайдер"
              rgOptions={Object.entries(WEBDAV_PROVIDERS).map(([key, provider]) => ({ data: key, label: provider.name }))}
              selectedOption={settings.webdavProvider}
              onChange={(opt) => {
                const key = opt.data as WebDAVProviderType;
                const next: Settings = {
                  ...settings,
                  webdavProvider: key,
                  webdavUrl: key === "custom" ? settings.webdavUrl : WEBDAV_PROVIDERS[key].url,
                };
                setSettings(next);
                setTimeout(() => saveStorageConfig(next), 100);
              }}
            />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField
              label="URL"
              value={settings.webdavUrl || ""}
              onChange={(e) => update({ webdavUrl: e.target.value })}
              description={WEBDAV_PROVIDERS[settings.webdavProvider || "custom"]?.description || "URL WebDAV сервера"}
            />
          </PanelSectionRow>
          {settings.webdavProvider === "yandex" && (
            <PanelSectionRow>
              <TextField
                label="OAuth токен (опционально)"
                value={settings.webdavOAuthToken || ""}
                onChange={(e) => update({ webdavOAuthToken: e.target.value })}
                description="Оставьте пустым, чтобы использовать логин и пароль приложения"
              />
            </PanelSectionRow>
          )}
          {!settings.webdavOAuthToken && (
            <>
              <PanelSectionRow>
                <TextField
                  label="Логин"
                  value={settings.webdavUsername || ""}
                  onChange={(e) => update({ webdavUsername: e.target.value })}
                />
              </PanelSectionRow>
              <PanelSectionRow>
                <TextField
                  label="Пароль"
                  value={settings.webdavPassword || ""}
                  onChange={(e) => update({ webdavPassword: e.target.value })}
                  bIsPassword
                />
              </PanelSectionRow>
            </>
          )}
          <PanelSectionRow>
            <Hint>Nextcloud, Яндекс Диск, Box, ownCloud и другие WebDAV‑серверы.</Hint>
          </PanelSectionRow>
        </PanelSection>
      )}

      {settings.storageProvider === "s3" && (
        <PanelSection title="S3 / Object Storage">
          <PanelSectionRow>
            <Dropdown
              menuLabel="Провайдер S3"
              rgOptions={Object.entries(S3_PROVIDERS).map(([key, provider]) => ({ data: key, label: provider.name }))}
              selectedOption={settings.s3Provider}
              onChange={(opt) => {
                const key = opt.data as S3ProviderType;
                const preset = S3_PROVIDERS[key];
                const next: Settings = {
                  ...settings,
                  s3Provider: key,
                  s3Endpoint: preset.endpoint || settings.s3Endpoint,
                  s3Region: preset.region || settings.s3Region,
                  s3PathStyle: preset.pathStyle,
                };
                setSettings(next);
                setTimeout(() => saveStorageConfig(next), 100);
              }}
            />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Bucket" value={settings.s3Bucket || ""} onChange={(e) => update({ s3Bucket: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Endpoint" value={settings.s3Endpoint || ""} onChange={(e) => update({ s3Endpoint: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Region" value={settings.s3Region || ""} onChange={(e) => update({ s3Region: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Access Key" value={settings.s3AccessKey || ""} onChange={(e) => update({ s3AccessKey: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField
              label="Secret Key"
              value={settings.s3SecretKey || ""}
              onChange={(e) => update({ s3SecretKey: e.target.value })}
              bIsPassword
            />
          </PanelSectionRow>
        </PanelSection>
      )}

      {settings.storageProvider === "ftp" && (
        <PanelSection title="FTP / FTPS">
          <PanelSectionRow>
            <TextField label="Хост" value={settings.ftpHost || ""} onChange={(e) => update({ ftpHost: e.target.value })} description="ftp.example.com" />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Порт" value={settings.ftpPort || ""} onChange={(e) => update({ ftpPort: e.target.value })} description="по умолчанию 21" />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Логин" value={settings.ftpUsername || ""} onChange={(e) => update({ ftpUsername: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Пароль" value={settings.ftpPassword || ""} onChange={(e) => update({ ftpPassword: e.target.value })} bIsPassword />
          </PanelSectionRow>
          <PanelSectionRow>
            <ToggleField label="Использовать TLS (FTPS)" checked={settings.ftpUseTls} onChange={(v) => update({ ftpUseTls: v })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <ToggleField label="Пассивный режим (PASV)" checked={settings.ftpPassive} onChange={(v) => update({ ftpPassive: v })} />
          </PanelSectionRow>
        </PanelSection>
      )}

      {settings.storageProvider === "sftp" && (
        <PanelSection title="SFTP">
          <PanelSectionRow>
            <TextField label="Хост" value={settings.sftpHost || ""} onChange={(e) => update({ sftpHost: e.target.value })} description="sftp.example.com" />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Порт" value={settings.sftpPort || ""} onChange={(e) => update({ sftpPort: e.target.value })} description="по умолчанию 22" />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Логин" value={settings.sftpUsername || ""} onChange={(e) => update({ sftpUsername: e.target.value })} />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField label="Пароль" value={settings.sftpPassword || ""} onChange={(e) => update({ sftpPassword: e.target.value })} bIsPassword />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField
              label="Приватный ключ (опционально)"
              value={settings.sftpKeyPath || ""}
              onChange={(e) => update({ sftpKeyPath: e.target.value })}
              description="/home/deck/.ssh/id_ed25519"
            />
          </PanelSectionRow>
          <PanelSectionRow>
            <TextField
              label="Пароль ключа (опционально)"
              value={settings.sftpKeyPassphrase || ""}
              onChange={(e) => update({ sftpKeyPassphrase: e.target.value })}
              bIsPassword
            />
          </PanelSectionRow>
        </PanelSection>
      )}

      <PanelSection>
        <PanelSectionRow>
          <div style={actionRow}>
            <div style={{ flex: 1 }}>
              <ButtonItem layout="below" onClick={testConnection} disabled={testing}>
                {testing ? "Проверка…" : "Тест подключения"}
              </ButtonItem>
            </div>
            <div style={{ flex: 1 }}>
              <ButtonItem layout="below" onClick={() => saveStorageConfig()}>
                Сохранить
              </ButtonItem>
            </div>
          </div>
        </PanelSectionRow>
        {testResult && (
          <PanelSectionRow>
            <StatusMessage tone={testResult.tone}>{testResult.text}</StatusMessage>
          </PanelSectionRow>
        )}
      </PanelSection>

      <PanelSection title="Автосинхронизация">
        <PanelSectionRow>
          <ToggleField
            label="Синхронизировать при выходе из игры"
            checked={settings.autoSync}
            onChange={async (value) => {
              update({ autoSync: value });
              try {
                await call("enable_auto_sync", { enabled: value });
              } catch (error) {
                console.error("Error enabling auto-sync:", error);
              }
            }}
          />
        </PanelSectionRow>
      </PanelSection>

      <PanelSection title="Глобальные пути сохранений">
        <PanelSectionRow>
          <Hint>Применяются ко всем играм в дополнение к индивидуальным путям.</Hint>
        </PanelSectionRow>
        <PanelSectionRow>
          <TextField label="Добавить путь" value={newPath} onChange={(e) => setNewPath(e.target.value)} />
        </PanelSectionRow>
        <PanelSectionRow>
          <ButtonItem layout="below" onClick={addDefaultPath} disabled={validating || !newPath.trim()}>
            {validating ? "Проверка…" : "Добавить путь"}
          </ButtonItem>
        </PanelSectionRow>
        {pathValidationResult && (
          <PanelSectionRow>
            <StatusMessage tone={pathValidationResult.startsWith("✓") ? "success" : "error"}>
              {pathValidationResult}
            </StatusMessage>
          </PanelSectionRow>
        )}
        {settings.defaultSavePaths.length === 0 ? (
          <PanelSectionRow>
            <div style={{ color: colors.dim, fontSize: "12px", fontStyle: "italic" }}>Нет добавленных путей</div>
          </PanelSectionRow>
        ) : (
          settings.defaultSavePaths.map((path, index) => (
            <PanelSectionRow key={index}>
              <div style={{ ...card, display: "flex", flexDirection: "column", gap: "8px" }}>
                <div style={{ fontSize: "11px", color: "#c7ccd1", wordBreak: "break-all", fontFamily: "monospace" }}>{path}</div>
                <div style={actionRow}>
                  <ButtonItem layout="below" onClick={() => removeDefaultPath(index)}>
                    Удалить
                  </ButtonItem>
                </div>
              </div>
            </PanelSectionRow>
          ))
        )}
      </PanelSection>

      <PanelSection title="Опасная зона">
        <PanelSectionRow>
          <Hint>Удаляет все настройки, кэш, изученные пути и конфигурацию плагина.</Hint>
        </PanelSectionRow>
        <PanelSectionRow>
          <ButtonItem
            layout="below"
            onClick={async () => {
              if (!confirm("Удалить все данные плагина? Действие необратимо.")) return;
              try {
                const result: any = await call("clear_all_data", {});
                setTestResult(
                  result.success
                    ? { tone: "success", text: result.message || "Все данные очищены" }
                    : { tone: "error", text: result.error || "Ошибка очистки" }
                );
                setSettings(loadSettings());
              } catch (error: any) {
                setTestResult({ tone: "error", text: error?.message || String(error) });
              }
            }}
          >
            Очистить все данные плагина
          </ButtonItem>
        </PanelSectionRow>
      </PanelSection>
    </div>
  );
}
