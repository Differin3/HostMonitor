import { useState, useEffect } from "react";
import { Tabs } from "@decky/ui";
import { Settings } from "./Settings";
import { GamePathsTab } from "./GamePathsTab";
import { StatsTab } from "./StatsTab";

enum SettingsTab {
  MAIN = "main",
  GAME_PATHS = "game_paths",
  STATS = "stats",
}

export function SettingsPage() {
  const [currentTab, setCurrentTab] = useState<string>(SettingsTab.MAIN);

  useEffect(() => {
    const handleTabSwitch = (event: CustomEvent) => {
      const tab = event.detail;
      if (tab === "game_paths" || tab === "main" || tab === "stats") {
        setCurrentTab(tab);
      }
    };
    window.addEventListener("gamesync-switch-tab" as any, handleTabSwitch as EventListener);
    return () => {
      window.removeEventListener("gamesync-switch-tab" as any, handleTabSwitch as EventListener);
    };
  }, []);

  return (
    <div style={{ paddingTop: "var(--basicui-header-height, 50px)", minHeight: "100vh" }}>
      <Tabs
        activeTab={currentTab}
        onShowTab={(tab: string) => setCurrentTab(tab)}
        tabs={[
        {
          id: SettingsTab.MAIN,
          title: "Основные",
          content: <Settings />,
        },
        {
          id: SettingsTab.GAME_PATHS,
          title: "Пути игр",
          content: <GamePathsTab />,
        },
        {
          id: SettingsTab.STATS,
          title: "Статистика",
          content: <StatsTab />,
        },
      ]}
      />
    </div>
  );
}
