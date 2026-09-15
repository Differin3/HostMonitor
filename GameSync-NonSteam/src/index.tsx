import { definePlugin, routerHook } from "@decky/api";
import { staticClasses } from "@decky/ui";
import { FaCloud } from "react-icons/fa";
import { GameSyncMain } from "./components/GameSyncMain";
import { SettingsPage } from "./components/SettingsPage";

export default definePlugin(() => {
  // Регистрация маршрута для полноразмерной страницы настроек
  routerHook.addRoute("/gamesync-settings", () => {
    return <SettingsPage />;
  });

  return {
    name: "GameSync NonSteam",
    titleView: <div className={staticClasses.Title}>GameSync</div>,
    content: (
      <div>
        <GameSyncMain />
      </div>
    ),
    icon: <FaCloud />,
    onDismount() {
      routerHook.removeRoute("/gamesync-settings");
    },
  };
});