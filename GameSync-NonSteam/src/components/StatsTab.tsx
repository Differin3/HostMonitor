import { useState, useEffect } from "react";
import { call } from "@decky/api";
import { PanelSection, PanelSectionRow } from "@decky/ui";
import { SyncStats } from "../utils/types";
import { EmptyState, ErrorState, Loading } from "./ui";
import { colors, card } from "../utils/theme";

const formatBytes = (bytes: number): string => {
  if (!bytes) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  return `${Math.round((bytes / Math.pow(k, i)) * 100) / 100} ${sizes[i]}`;
};

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

function StatRow({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ ...card, display: "flex", justifyContent: "space-between", alignItems: "center", gap: "10px" }}>
      <span style={{ fontSize: "12px", color: colors.muted }}>{label}</span>
      <span style={{ fontSize: "13px", fontWeight: 600, color: colors.text }}>{value}</span>
    </div>
  );
}

export function StatsTab() {
  const [stats, setStats] = useState<SyncStats | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const loadStats = async () => {
    setLoading(true);
    setError(null);
    try {
      const result: any = await call("get_sync_stats", {});
      if (result.success && result.stats) {
        setStats(result.stats);
      } else {
        setError(result.error || "Ошибка загрузки статистики");
      }
    } catch (err) {
      setError(`Ошибка: ${err}`);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadStats();
  }, []);

  if (loading) return <Loading text="Загрузка статистики..." />;
  if (error) return <ErrorState text={error} onRetry={loadStats} />;
  if (!stats) return <EmptyState text="Нет данных для отображения" />;

  return (
    <PanelSection title="Статистика синхронизаций">
      <PanelSectionRow>
        <div style={{ display: "flex", flexDirection: "column", gap: "6px", width: "100%" }}>
          <StatRow label="Всего синхронизаций" value={stats.totalSyncs.toLocaleString()} />
          <StatRow label="Игр синхронизировано" value={stats.gamesCount.toLocaleString()} />
          {stats.lastSync && <StatRow label="Последняя синхронизация" value={formatDate(stats.lastSync)} />}
          {typeof stats.totalSize === "number" && stats.totalSize > 0 && (
            <StatRow label="Общий размер" value={formatBytes(stats.totalSize)} />
          )}
        </div>
      </PanelSectionRow>

      {stats.syncsByDate && stats.syncsByDate.length > 0 && (
        <>
          <PanelSectionRow>
            <div style={{ fontSize: "12px", fontWeight: 600, color: colors.muted, marginTop: "6px" }}>
              Синхронизации по датам
            </div>
          </PanelSectionRow>
          <PanelSectionRow>
            <div style={{ display: "flex", flexDirection: "column", gap: "6px", width: "100%" }}>
              {stats.syncsByDate.slice(0, 10).map((item, index) => (
                <StatRow
                  key={index}
                  label={new Date(item.date).toLocaleDateString("ru-RU")}
                  value={item.count.toLocaleString()}
                />
              ))}
            </div>
          </PanelSectionRow>
        </>
      )}
    </PanelSection>
  );
}
