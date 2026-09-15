import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { PanelSection, PanelSectionRow, ButtonItem, TextField } from "@decky/ui";
import { GameInfo } from "../utils/types";
import { loadSettings } from "../utils/Settings";
import { Badge, EmptyState, ErrorState, Loading, PathText, StatusMessage } from "./ui";
import { colors, card, actionRow, dimText } from "../utils/theme";

const sourceLabel = (source: string): string => {
  switch (source) {
    case "known":
      return "база";
    case "learned":
      return "изучено";
    case "heuristic":
      return "поиск";
    default:
      return source;
  }
};

export function GamePathsTab() {
  const [games, setGames] = useState<GameInfo[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [editingGame, setEditingGame] = useState<GameInfo | null>(null);
  const [editingPathIndex, setEditingPathIndex] = useState<number | null>(null);
  const [editingPathValue, setEditingPathValue] = useState<string>("");
  const [newPath, setNewPath] = useState<string>("");
  const [validating, setValidating] = useState<boolean>(false);
  const [pathValidationResult, setPathValidationResult] = useState<string | null>(null);

  const loadGames = async () => {
    setLoading(true);
    setError(null);
    try {
      const result: any = await call("scan_games", {});
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

  const persistPaths = async (game: GameInfo, savePaths: string[], excludePaths?: string[]): Promise<boolean> => {
    try {
      const payload: any = { game_name: game.name, save_paths: savePaths };
      if (excludePaths !== undefined) payload.exclude_paths = excludePaths;
      const result: any = await call("update_game_paths", payload);
      if (result.success) {
        await loadGames();
        return true;
      }
      setPathValidationResult(`✗ ${result.error || "Ошибка сохранения"}`);
      return false;
    } catch (err) {
      setPathValidationResult(`✗ Ошибка: ${err}`);
      return false;
    }
  };

  const addPathToGame = async (game: GameInfo) => {
    if (!newPath.trim()) {
      setPathValidationResult("Введите путь");
      return;
    }
    setValidating(true);
    setPathValidationResult(null);
    try {
      const result: any = await call("validate_save_path", { path: newPath.trim() });
      if (result.success && result.path) {
        if (game.savePaths.includes(result.path)) {
          setPathValidationResult("Этот путь уже добавлен");
        } else if (await persistPaths(game, [...game.savePaths, result.path])) {
          setNewPath("");
        }
      } else {
        setPathValidationResult(`✗ ${result.error || "Ошибка валидации"}`);
      }
    } finally {
      setValidating(false);
    }
  };

  const addCandidateToGame = async (game: GameInfo, candidatePath: string) => {
    if (game.savePaths.includes(candidatePath)) return;
    await persistPaths(game, [...game.savePaths, candidatePath]);
  };

  const removePathFromGame = async (game: GameInfo, pathIndex: number) => {
    await persistPaths(game, game.savePaths.filter((_, i) => i !== pathIndex));
  };

  const excludePathFromGame = async (game: GameInfo, path: string) => {
    const updatedPaths = game.savePaths.filter((p) => p !== path);
    const updatedExcludes = Array.from(new Set([...(game.excludePaths || []), path]));
    await persistPaths(game, updatedPaths, updatedExcludes);
  };

  const startEditingPath = (game: GameInfo, pathIndex: number) => {
    setEditingGame(game);
    setEditingPathIndex(pathIndex);
    setEditingPathValue(game.savePaths[pathIndex]);
  };

  const saveEditedPath = async (game: GameInfo, pathIndex: number) => {
    if (!editingPathValue.trim()) {
      setPathValidationResult("Введите путь");
      return;
    }
    setValidating(true);
    setPathValidationResult(null);
    try {
      const result: any = await call("validate_save_path", { path: editingPathValue.trim() });
      if (result.success && result.path) {
        const updatedPaths = [...game.savePaths];
        updatedPaths[pathIndex] = result.path;
        if (await persistPaths(game, updatedPaths)) {
          setEditingPathIndex(null);
          setEditingPathValue("");
        }
      } else {
        setPathValidationResult(`✗ ${result.error || "Ошибка валидации"}`);
      }
    } finally {
      setValidating(false);
    }
  };

  const cancelEditingPath = () => {
    setEditingPathIndex(null);
    setEditingPathValue("");
    setPathValidationResult(null);
  };

  useEffect(() => {
    loadGames();
  }, []);

  if (loading) return <Loading text="Загрузка игр..." />;
  if (error) return <ErrorState text={error} onRetry={loadGames} />;

  const settings = loadSettings();

  return (
    <div>
      <PanelSection title={`Управление путями (${games.length})`}>
        <PanelSectionRow>
          <ButtonItem layout="below" onClick={loadGames} disabled={loading}>
            Обновить список
          </ButtonItem>
        </PanelSectionRow>
      </PanelSection>

      {games.length === 0 && <EmptyState text="Игры не найдены" />}

      {games.map((game, index) => {
        const isEditing = editingGame?.name === game.name;
        const candidates = (game.saveCandidates || []).filter((c) => !game.savePaths.includes(c.path));

        return (
          <PanelSection key={index} title={game.name}>
            <PanelSectionRow>
              <div style={{ display: "flex", flexWrap: "wrap", gap: "6px", alignItems: "center" }}>
                <Badge tone={game.hasSaves ? "success" : "warning"}>
                  {game.hasSaves ? `${game.savePaths.length} путей` : "нет сохранений"}
                </Badge>
                {typeof game.steamAppId === "number" && <Badge>appid {game.steamAppId}</Badge>}
                {(game.excludePaths?.length || 0) > 0 && <Badge tone="error">исключено {game.excludePaths!.length}</Badge>}
              </div>
            </PanelSectionRow>

            {game.sharedPrefix && (
              <PanelSectionRow>
                <StatusMessage tone="warning">
                  Общий префикс с: {(game.sharedWith || []).join(", ")}
                </StatusMessage>
              </PanelSectionRow>
            )}

            {settings.defaultSavePaths.length > 0 && (
              <PanelSectionRow>
                <div style={dimText}>
                  Глобальные пути: {settings.defaultSavePaths.length} (применяются ко всем играм)
                </div>
              </PanelSectionRow>
            )}

            {game.savePaths.map((path, pathIndex) => {
              const isEditingThisPath = editingGame?.name === game.name && editingPathIndex === pathIndex;
              return (
                <PanelSectionRow key={pathIndex}>
                  {isEditingThisPath ? (
                    <div style={{ ...card, display: "flex", flexDirection: "column", gap: "8px" }}>
                      <TextField label="Путь" value={editingPathValue} onChange={(e) => setEditingPathValue(e.target.value)} />
                      {pathValidationResult && (
                        <StatusMessage tone={pathValidationResult.startsWith("✓") ? "success" : "error"}>
                          {pathValidationResult}
                        </StatusMessage>
                      )}
                      <div style={actionRow}>
                        <div style={{ flex: 1 }}>
                          <ButtonItem
                            layout="below"
                            onClick={() => saveEditedPath(game, pathIndex)}
                            disabled={validating || !editingPathValue.trim()}
                          >
                            {validating ? "Проверка…" : "Сохранить"}
                          </ButtonItem>
                        </div>
                        <div style={{ flex: 1 }}>
                          <ButtonItem layout="below" onClick={cancelEditingPath}>
                            Отмена
                          </ButtonItem>
                        </div>
                      </div>
                    </div>
                  ) : (
                    <div style={{ ...card, display: "flex", flexDirection: "column", gap: "8px" }}>
                      <PathText>{path}</PathText>
                      <div style={actionRow}>
                        <div style={{ flex: 1 }}>
                          <ButtonItem layout="below" onClick={() => startEditingPath(game, pathIndex)}>
                            Изменить
                          </ButtonItem>
                        </div>
                        <div style={{ flex: 1 }}>
                          <ButtonItem layout="below" onClick={() => excludePathFromGame(game, path)}>
                            Исключить
                          </ButtonItem>
                        </div>
                        <div style={{ flex: 1 }}>
                          <ButtonItem layout="below" onClick={() => removePathFromGame(game, pathIndex)}>
                            Удалить
                          </ButtonItem>
                        </div>
                      </div>
                    </div>
                  )}
                </PanelSectionRow>
              );
            })}

            {candidates.length > 0 && (
              <PanelSectionRow>
                <div style={{ display: "flex", flexDirection: "column", gap: "6px", width: "100%" }}>
                  <div style={{ ...dimText, fontWeight: 600 }}>Найденные кандидаты ({candidates.length})</div>
                  {candidates.map((c, ci) => (
                    <div key={ci} style={{ ...card, display: "flex", flexDirection: "column", gap: "6px" }}>
                      <PathText>{c.path}</PathText>
                      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: "8px" }}>
                        <span style={{ fontSize: "10px", color: colors.dim }}>
                          источник: {sourceLabel(c.source)} · оценка: {c.score}
                          {c.fileCount ? ` · файлов: ${c.fileCount}` : ""}
                        </span>
                        <div style={{ minWidth: "110px" }}>
                          <ButtonItem layout="below" onClick={() => addCandidateToGame(game, c.path)}>
                            Добавить
                          </ButtonItem>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </PanelSectionRow>
            )}

            {isEditing && (
              <>
                <PanelSectionRow>
                  <TextField label="Добавить путь" value={newPath} onChange={(e) => setNewPath(e.target.value)} />
                </PanelSectionRow>
                <PanelSectionRow>
                  <ButtonItem layout="below" onClick={() => addPathToGame(game)} disabled={validating || !newPath.trim()}>
                    {validating ? "Проверка…" : "Добавить"}
                  </ButtonItem>
                </PanelSectionRow>
                {pathValidationResult && !editingPathIndex && (
                  <PanelSectionRow>
                    <StatusMessage tone={pathValidationResult.startsWith("✓") ? "success" : "error"}>
                      {pathValidationResult}
                    </StatusMessage>
                  </PanelSectionRow>
                )}
              </>
            )}

            <PanelSectionRow>
              <ButtonItem
                layout="below"
                onClick={() => {
                  if (isEditing) {
                    setEditingGame(null);
                    setNewPath("");
                    setPathValidationResult(null);
                  } else {
                    setEditingGame(game);
                  }
                }}
              >
                {isEditing ? "Готово" : "Редактировать пути"}
              </ButtonItem>
            </PanelSectionRow>
          </PanelSection>
        );
      })}
    </div>
  );
}
