export type WebDAVProviderType = 'custom' | 'nextcloud' | 'yandex' | 'box' | 'owncloud';

export type S3ProviderType = 'custom' | 'yandex' | 'vk' | 'cloudru' | 'aws' | 'backblaze' | 'wasabi' | 'digitalocean';

export type StorageProviderType = 'webdav' | 's3' | 'ftp' | 'sftp';

export interface Settings {
  storageProvider: StorageProviderType;
  // WebDAV
  webdavProvider: WebDAVProviderType;
  webdavUrl: string;
  webdavUsername: string;
  webdavPassword: string;
  webdavOAuthToken: string; // Для Яндекс Диска через OAuth
  // S3
  s3Provider: S3ProviderType;
  s3Endpoint: string;
  s3Region: string;
  s3Bucket: string;
  s3AccessKey: string;
  s3SecretKey: string;
  s3PathStyle: boolean;
  s3SignatureVersion: string;
  // FTP / FTPS
  ftpHost: string;
  ftpPort: string;
  ftpUsername: string;
  ftpPassword: string;
  ftpUseTls: boolean;
  ftpPassive: boolean;
  // SFTP
  sftpHost: string;
  sftpPort: string;
  sftpUsername: string;
  sftpPassword: string;
  sftpKeyPath: string;
  sftpKeyPassphrase: string;
  // Общие
  autoSync: boolean;
  defaultSavePaths: string[];
}

export const WEBDAV_PROVIDERS: Record<WebDAVProviderType, { name: string; url: string; description: string }> = {
  custom: { name: 'Другой', url: '', description: 'Введите URL вручную' },
  nextcloud: { name: 'Nextcloud', url: 'https://nextcloud.com/remote.php/dav/files/USERNAME/', description: 'Бесплатный облачный хостинг' },
  yandex: { name: 'Яндекс Диск', url: 'https://webdav.yandex.ru', description: '10GB бесплатно, Basic или OAuth' },
  box: { name: 'Box', url: 'https://dav.box.com/dav/', description: 'Корпоративное хранилище' },
  owncloud: { name: 'ownCloud', url: 'https://your-server.com/remote.php/dav/files/USERNAME/', description: 'Свой сервер ownCloud' }
};

export const S3_PROVIDERS: Record<S3ProviderType, { name: string; endpoint: string; region: string; pathStyle: boolean }> = {
  custom: { name: 'Другой S3', endpoint: '', region: 'us-east-1', pathStyle: false },
  yandex: { name: 'Yandex Object Storage', endpoint: 'https://storage.yandexcloud.net', region: 'ru-central1', pathStyle: false },
  vk: { name: 'VK Cloud', endpoint: 'https://s3.mcs.mail.ru', region: 'ru-1', pathStyle: true },
  cloudru: { name: 'Cloud.ru', endpoint: 'https://s3.cloud.ru', region: 'ru-central1', pathStyle: true },
  aws: { name: 'AWS S3', endpoint: 'https://s3.amazonaws.com', region: 'us-east-1', pathStyle: false },
  backblaze: { name: 'Backblaze B2', endpoint: 'https://s3.us-west-004.backblazeb2.com', region: 'us-west-004', pathStyle: true },
  wasabi: { name: 'Wasabi', endpoint: 'https://s3.wasabisys.com', region: 'us-east-1', pathStyle: true },
  digitalocean: { name: 'DigitalOcean Spaces', endpoint: 'https://nyc3.digitaloceanspaces.com', region: 'nyc3', pathStyle: true }
};

export const DEFAULT_SETTINGS: Settings = {
  storageProvider: 'webdav',
  webdavProvider: 'custom',
  webdavUrl: "",
  webdavUsername: "",
  webdavPassword: "",
  webdavOAuthToken: "",
  s3Provider: 'custom',
  s3Endpoint: "",
  s3Region: "us-east-1",
  s3Bucket: "",
  s3AccessKey: "",
  s3SecretKey: "",
  s3PathStyle: false,
  s3SignatureVersion: "s3v4",
  ftpHost: "",
  ftpPort: "21",
  ftpUsername: "",
  ftpPassword: "",
  ftpUseTls: false,
  ftpPassive: true,
  sftpHost: "",
  sftpPort: "22",
  sftpUsername: "",
  sftpPassword: "",
  sftpKeyPath: "",
  sftpKeyPassphrase: "",
  autoSync: false,
  defaultSavePaths: []
};

const SETTINGS_CHANGE_EVENT = 'gamesync-settings-change';

export function loadSettings(): Settings {
  try {
    const saved = localStorage.getItem('gamesyncSettings');
    if (saved) {
      return { ...DEFAULT_SETTINGS, ...JSON.parse(saved) };
    }
  } catch (error) {
    console.error('Failed to load settings:', error);
  }
  return DEFAULT_SETTINGS;
}

export function saveSettings(settings: Settings): void {
  try {
    localStorage.setItem('gamesyncSettings', JSON.stringify(settings));
    window.dispatchEvent(new CustomEvent(SETTINGS_CHANGE_EVENT, { detail: settings }));
  } catch (error) {
    console.error('Failed to save settings:', error);
  }
}

export function subscribeToSettings(callback: (settings: Settings) => void): () => void {
  const handler = (event: CustomEvent<Settings>) => callback(event.detail);
  window.addEventListener(SETTINGS_CHANGE_EVENT as any, handler as EventListener);
  return () => window.removeEventListener(SETTINGS_CHANGE_EVENT as any, handler as EventListener);
}
