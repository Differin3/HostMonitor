import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { ButtonItem, PanelSection, PanelSectionRow, Spinner, Router } from "@decky/ui";
import { GameInfo, SyncStatus } from "../utils/types";
import { loadSettings } from "../utils/Settings";
import { Badge, EmptyState, ErrorState, Loading, PathText, StatusMessage } from "./ui";
import { card, actionRow, dimText } from "../utils/theme";

export function GameList() {
  const [games, setGames] = useState<GameInfo[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [syncing, setSyncing] = useState<{ [key: string]: boolean }>({});
  const [syncStatus, setSyncStatus] = useState<{ [key: string]: SyncStatus }>({});

  const loadGames = async (forceRefresh: boolean = false) => {
    setLoading(true);
    setError(null);
    try {
      const result: any = await call("scan_games", { force_refresh: forceRefresh });
      if (result.success && result.games) {
        setGames(result.games);
      } else {
        setError(result.error || "Ошибка загрузки игр");
      }
    } catch (err) {
      setError(`Ошибка: ${err}`);
    } finally {
      setLoading(false);
    }
  };

  const syncGame = async (game: GameInfo) => {
    const settings = loadSettings();
    const allSavePaths = [...settings.defaultSavePaths, ...game.savePaths];
    const uniquePaths = Array.from(new Set(allSavePaths));

    if (uniquePaths.length === 0) {
      setSyncStatus((prev) => ({
        ...prev,
        [game.name]: { gameName: game.name, status: "error", message: "Нет путей сохранений" },
      }));
      return;
    }

    setSyncing((prev) => ({ ...prev, [game.name]: true }));
    setSyncStatus((prev) => ({ ...prev, [game.name]: { gameName: game.name, status: "syncing" } }));

    try {
      const result: any = await call("sync_game", { game_name: game.name, save_paths: uniquePaths });
      setSyncStatus((prev) => ({
        ...prev,
        [game.name]: {
          gameName: game.name,
          status: result.success ? "success" : "error",
          message: result.success ? result.message || "Синхронизация завершена" : result.error || "Ошибка синхронизации",
        },
      }));
    } catch (err) {
      setSyncStatus((prev) => ({
        ...prev,
        [game.name]: { gameName: game.name, status: "error", message: `Ошибка: ${err}` },
      }));
    } finally {
      setSyncing((prev) => ({ ...prev, [game.name]: false }));
    }
  };

  useEffect(() => {
    loadGames();
  }, []);

  return (
    <div>
      <PanelSection title={`Найденные игры (${games.length})`}>
        <PanelSectionRow>
          <div style={actionRow}>
            <div style={{ flex: 1 }}>
              <ButtonItem layout="below" onClick={() => loadGames(true)} disabled={loading}>
                {loading ? "Сканирование..." : "Обновить"}
              </ButtonItem>
            </div>
            <div style={{ flex: 1 }}>
              <ButtonItem
                layout="below"
                onClick={() => {
                  Router.CloseSideMenus();
                  Router.Navigate("/gamesync-settings");
                  setTimeout(() => window.dispatchEvent(new CustomEvent("gamesync-switch-tab", { detail: "game_paths" })), 100);
                }}
              >
                Настроить пути
              </ButtonItem>
            </div>
          </div>
        </PanelSectionRow>
      </PanelSection>

      {loading && <Loading text="Сканирование игр PortProton..." />}
      {error && !loading && <ErrorState text={error} onRetry={() => loadGames()} />}
      {games.length === 0 && !loading && !error && <EmptyState text="Игры не найдены" />}

      {!loading &&
        games.map((game, index) => {
          const isSyncing = syncing[game.name];
          const status = syncStatus[game.name];

          return (
            <PanelSection key={index} title={game.name}>
              <PanelSectionRow>
                <div style={{ display: "flex", flexWrap: "wrap", gap: "6px", alignItems: "center" }}>
                  <Badge tone={game.hasSaves ? "success" : "warning"}>
                    {game.hasSaves ? `${game.savePaths.length} путей` : "нет сохранений"}
                  </Badge>
                  {typeof game.steamAppId === "number" && <Badge>appid {game.steamAppId}</Badge>}
                  {game.sharedPrefix && <Badge tone="warning">общий префикс</Badge>}
                </div>
              </PanelSectionRow>

              {game.savePaths.length > 0 && (
                <PanelSectionRow>
                  <div style={card}>
                    <div style={{ ...dimText, marginBottom: "6px" }}>Пути сохранений:</div>
                    {game.savePaths.slice(0, 3).map((path, pathIdx) => (
                      <div key={pathIdx} style={{ marginBottom: pathIdx < Math.min(game.savePaths.length, 3) - 1 ? "6px" : 0 }}>
                        <PathText>{path}</PathText>
                      </div>
                    ))}
                    {game.savePaths.length > 3 && (
                      <div style={{ ...dimText, marginTop: "6px" }}>+{game.savePaths.length - 3} ещё…</div>
                    )}
                  </div>
                </PanelSectionRow>
              )}

              <PanelSectionRow>
                <div style={actionRow}>
                  <div style={{ flex: 1 }}>
                    <ButtonItem
                      layout="below"
                      onClick={() => {
                        Router.CloseSideMenus();
                        Router.Navigate("/gamesync-settings");
                        setTimeout(() => window.dispatchEvent(new CustomEvent("gamesync-switch-tab", { detail: "game_paths" })), 100);
                      }}
                    >
                      Изменить пути
                    </ButtonItem>
                  </div>
                  <div style={{ flex: 1 }}>
                    <ButtonItem layout="below" onClick={() => syncGame(game)} disabled={isSyncing || !game.hasSaves}>
                      {isSyncing ? (
                        <span style={{ display: "flex", alignItems: "center", gap: "8px", justifyContent: "center" }}>
                          <Spinner />
                          <span>Синхронизация…</span>
                        </span>
                      ) : (
                        "Синхронизировать"
                      )}
                    </ButtonItem>
                  </div>
                </div>
              </PanelSectionRow>

              {status && (
                <PanelSectionRow>
                  <StatusMessage
                    tone={status.status === "success" ? "success" : status.status === "error" ? "error" : "info"}
                  >
                    {status.message || "Синхронизация…"}
                  </StatusMessage>
                </PanelSectionRow>
              )}
            </PanelSection>
          );
        })}
    </div>
  );
}
