import { ReactNode } from "react";
import { PanelSection, PanelSectionRow, Spinner, ButtonItem } from "@decky/ui";
import { colors, radius, pathText } from "../utils/theme";

export type Tone = "success" | "error" | "warning" | "info";

const toneColor: Record<Tone, string> = {
  success: colors.success,
  error: colors.error,
  warning: colors.warning,
  info: colors.accent,
};

const toneBg: Record<Tone, string> = {
  success: "rgba(91, 184, 91, 0.12)",
  error: "rgba(224, 96, 96, 0.12)",
  warning: "rgba(224, 169, 74, 0.12)",
  info: "rgba(102, 170, 255, 0.12)",
};

/** Короткая подсказка под заголовком. */
export function Hint({ children }: { children: ReactNode }) {
  return <div style={{ fontSize: "12px", color: colors.muted, lineHeight: 1.4 }}>{children}</div>;
}

/** Цветное сообщение статуса (успех/ошибка/предупреждение). */
export function StatusMessage({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "8px",
        padding: "8px 10px",
        borderRadius: radius.sm,
        background: toneBg[tone],
        borderLeft: `3px solid ${toneColor[tone]}`,
        color: colors.text,
        fontSize: "12px",
        lineHeight: 1.4,
        width: "100%",
        boxSizing: "border-box",
      }}
    >
      {children}
    </div>
  );
}

/** Небольшой цветной бейдж. */
export function Badge({ tone = "info", children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span
      style={{
        display: "inline-block",
        padding: "1px 7px",
        borderRadius: "999px",
        fontSize: "10px",
        fontWeight: 600,
        color: toneColor[tone],
        background: toneBg[tone],
        border: `1px solid ${toneColor[tone]}44`,
        whiteSpace: "nowrap",
      }}
    >
      {children}
    </span>
  );
}

/** Моноширинный путь с переносом. */
export function PathText({ children }: { children: ReactNode }) {
  return <div style={pathText}>{children}</div>;
}

export function Loading({ text }: { text: string }) {
  return (
    <PanelSection>
      <PanelSectionRow>
        <div style={{ display: "flex", alignItems: "center", gap: "10px", color: colors.muted, fontSize: "12px" }}>
          <Spinner />
          <span>{text}</span>
        </div>
      </PanelSectionRow>
    </PanelSection>
  );
}

export function EmptyState({ text }: { text: string }) {
  return (
    <PanelSection>
      <PanelSectionRow>
        <div style={{ textAlign: "center", color: colors.dim, fontSize: "12px", padding: "12px 0" }}>{text}</div>
      </PanelSectionRow>
    </PanelSection>
  );
}

export function ErrorState({ text, onRetry }: { text: string; onRetry?: () => void }) {
  return (
    <PanelSection>
      <PanelSectionRow>
        <StatusMessage tone="error">{text}</StatusMessage>
      </PanelSectionRow>
      {onRetry && (
        <PanelSectionRow>
          <ButtonItem layout="below" onClick={onRetry}>
            Повторить
          </ButtonItem>
        </PanelSectionRow>
      )}
    </PanelSection>
  );
}
