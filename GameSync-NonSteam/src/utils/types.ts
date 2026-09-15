// Кандидат пути сохранений, найденный автоопределением
export interface SaveCandidate {
  path: string;
  score: number;
  source: "known" | "learned" | "heuristic" | string;
  reason?: string;
  fileCount?: number;
  saveFileCount?: number;
  size?: number;
}

// Типы для игр
export interface GameInfo {
  name: string;
  prefixPath: string;
  savePaths: string[];
  hasSaves: boolean;
  desktopFile?: string;
  exec?: string;
  steamAppId?: number;
  sharedPrefix?: boolean;
  sharedWith?: string[];
  saveCandidates?: SaveCandidate[];
  excludePaths?: string[];
  lastSync?: string;
}

// Статус синхронизации
export interface SyncStatus {
  gameName: string;
  status: "idle" | "syncing" | "success" | "error";
  message?: string;
}

// Синхронизированная игра
export interface SyncedGame {
  gameName: string;
  lastSync: string; // ISO timestamp
  fileId?: string;
}

// Статистика синхронизаций
export interface SyncStats {
  totalSyncs: number;
  lastSync?: string;
  gamesCount: number;
  totalSize?: number;
  syncsByDate: { date: string; count: number }[];
}
