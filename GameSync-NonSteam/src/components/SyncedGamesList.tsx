import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { ButtonItem, PanelSection, PanelSectionRow, Spinner } from "@decky/ui";
import { SyncedGame } from "../utils/types";
import { loadSettings } from "../utils/Settings";
import { EmptyState, ErrorState, Loading, StatusMessage } from "./ui";
import { colors, actionRow } from "../utils/theme";

const formatDate = (isoString: string): string => {
  try {
    return new Date(isoString).toLocaleString("ru-RU", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return isoString;
  }
};

export function SyncedGamesList() {
  const [syncedGames, setSyncedGames] = useState<SyncedGame[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [syncing, setSyncing] = useState<{ [key: string]: boolean }>({});
  const [status, setStatus] = useState<string | null>(null);

  const loadSyncedGames = async () => {
    setLoading(true);
    setError(null);
    try {
      const result: any = await call("get_synced_games", {});
      if (result.success && result.games) {
        setSyncedGames(result.games);
      } else {
        setError(result.error || "Ошибка загрузки списка");
      }
    } catch (err) {
      setError(`Ошибка: ${err}`);
    } finally {
      setLoading(false);
    }
  };

  const resyncGame = async (game: SyncedGame) => {
    setSyncing((prev) => ({ ...prev, [game.gameName]: true }));
    setStatus(null);
    try {
      const scanResult: any = await call("scan_games", {});
      if (scanResult.success && scanResult.games) {
        const gameInfo = scanResult.games.find((g: any) => g.name === game.gameName);
        if (gameInfo && gameInfo.savePaths && gameInfo.savePaths.length > 0) {
          const settings = loadSettings();
          const uniquePaths = Array.from(new Set([...settings.defaultSavePaths, ...gameInfo.savePaths]));
          const syncResult: any = await call("sync_game", { game_name: game.gameName, save_paths: uniquePaths });
          if (syncResult.success) {
            await loadSyncedGames();
          } else {
            setStatus(syncResult.error || "Ошибка синхронизации");
          }
        } else {
          setStatus(`Для «${game.gameName}» не найдены пути сохранений`);
        }
      }
    } catch (err) {
      setStatus(`Ошибка: ${err}`);
    } finally {
      setSyncing((prev) => ({ ...prev, [game.gameName]: false }));
    }
  };

  useEffect(() => {
    loadSyncedGames();
  }, []);

  if (loading) {
    return <Loading text="Загрузка списка синхронизаций..." />;
  }

  if (error) {
    return <ErrorState text={error} onRetry={loadSyncedGames} />;
  }

  if (syncedGames.length === 0) {
    return <EmptyState text="Синхронизированных игр пока нет" />;
  }

  return (
    <PanelSection title={`Синхронизированные игры (${syncedGames.length})`}>
      <PanelSectionRow>
        <ButtonItem layout="below" onClick={loadSyncedGames} disabled={loading}>
          Обновить список
        </ButtonItem>
      </PanelSectionRow>

      {status && (
        <PanelSectionRow>
          <StatusMessage tone="error">{status}</StatusMessage>
        </PanelSectionRow>
      )}

      {syncedGames.map((game, index) => {
        const isSyncing = syncing[game.gameName];
        return (
          <PanelSectionRow key={index}>
            <div style={{ display: "flex", flexDirection: "column", gap: "6px", width: "100%" }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: "8px" }}>
                <span style={{ fontSize: "13px", fontWeight: 600, color: colors.text }}>{game.gameName}</span>
                <span style={{ fontSize: "11px", color: colors.dim }}>{formatDate(game.lastSync)}</span>
              </div>
              <div style={actionRow}>
                <ButtonItem layout="below" onClick={() => resyncGame(game)} disabled={isSyncing}>
                  {isSyncing ? (
                    <span style={{ display: "flex", alignItems: "center", gap: "8px", justifyContent: "center" }}>
                      <Spinner />
                      <span>Синхронизация…</span>
                    </span>
                  ) : (
                    "Синхронизировать снова"
                  )}
                </ButtonItem>
              </div>
            </div>
          </PanelSectionRow>
        );
      })}
    </PanelSection>
  );
}
