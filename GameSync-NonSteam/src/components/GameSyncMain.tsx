import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { ButtonItem, PanelSection, PanelSectionRow, Router } from "@decky/ui";
import { GameList } from "./GameList";
import { SyncedGamesList } from "./SyncedGamesList";
import { StatusMessage } from "./ui";
import { colors } from "../utils/theme";

export function GameSyncMain() {
  const [status, setStatus] = useState<string>("Проверка подключения...");
  const [connected, setConnected] = useState<boolean>(false);

  useEffect(() => {
    call("get_test", {})
      .then((result: any) => {
        if (result && result.success) {
          setConnected(true);
          setStatus("Плагин готов к работе");
        } else {
          setConnected(false);
          setStatus(result?.error || result?.message || "Неизвестная ошибка");
        }
      })
      .catch((error) => {
        setConnected(false);
        setStatus(String(error?.message || error));
      });
  }, []);

  return (
    <div>
      <PanelSection>
        <PanelSectionRow>
          <StatusMessage tone={connected ? "success" : "error"}>
            <span style={{ fontWeight: 600 }}>{connected ? "Подключено" : "Нет связи"}</span>
            <span style={{ color: colors.muted }}>· {status}</span>
          </StatusMessage>
        </PanelSectionRow>
        <PanelSectionRow>
          <ButtonItem
            layout="below"
            onClick={() => {
              Router.CloseSideMenus();
              Router.Navigate("/gamesync-settings");
            }}
          >
            Настройки и пути сохранений
          </ButtonItem>
        </PanelSectionRow>
      </PanelSection>

      {connected && (
        <>
          <SyncedGamesList />
          <GameList />
        </>
      )}
    </div>
  );
}
