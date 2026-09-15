import type { CSSProperties } from "react";

/** Единая палитра интерфейса (совместима с тёмной темой Steam). */
export const colors = {
  text: "#e8eaed",
  muted: "#9aa0a6",
  dim: "#71767b",
  success: "#5bb85b",
  error: "#e06060",
  warning: "#e0a94a",
  accent: "#66aaff",
  card: "rgba(255, 255, 255, 0.05)",
  cardSoft: "rgba(255, 255, 255, 0.035)",
  border: "rgba(255, 255, 255, 0.08)",
};

export const radius = { sm: 6, md: 8 };

export const card: CSSProperties = {
  background: colors.card,
  border: `1px solid ${colors.border}`,
  borderRadius: radius.md,
  padding: "8px 10px",
  width: "100%",
  boxSizing: "border-box",
};

export const cardSoft: CSSProperties = {
  ...card,
  background: colors.cardSoft,
};

export const mutedText: CSSProperties = {
  fontSize: "12px",
  color: colors.muted,
  lineHeight: 1.4,
};

export const dimText: CSSProperties = {
  fontSize: "11px",
  color: colors.dim,
  lineHeight: 1.4,
};

export const pathText: CSSProperties = {
  fontSize: "11px",
  color: "#c7ccd1",
  wordBreak: "break-all",
  fontFamily: "monospace",
  lineHeight: 1.35,
};

export const actionRow: CSSProperties = {
  display: "flex",
  gap: "8px",
  alignItems: "stretch",
  width: "100%",
};

export const column: CSSProperties = {
  display: "flex",
  flexDirection: "column",
  gap: "6px",
  width: "100%",
};
