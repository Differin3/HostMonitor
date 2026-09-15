#!/bin/bash
# Скрипт для скачивания иконок Lucide Icons локально

set -euo pipefail

ICONS_DIR="frontend/icons/lucide"
VERSION="latest"

echo "📥 Скачивание Lucide Icons..."

# Создаем директорию для иконок
mkdir -p "$ICONS_DIR"

# Скачиваем библиотеку Lucide
echo "📦 Скачивание библиотеки Lucide..."
if [ "$VERSION" = "latest" ]; then
    LUCIDE_VERSION=$(curl -s https://api.github.com/repos/lucide-icons/lucide/releases/latest | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/')
else
    LUCIDE_VERSION="$VERSION"
fi

echo "📌 Версия Lucide: $LUCIDE_VERSION"

# Скачиваем minified версию библиотеки
echo "⬇️ Скачивание lucide.min.js..."
curl -L "https://unpkg.com/lucide@${LUCIDE_VERSION}/dist/umd/lucide.min.js" -o "$ICONS_DIR/lucide.min.js"

# Скачиваем все SVG иконки (опционально, если нужны отдельные файлы)
echo "⬇️ Скачивание SVG иконок..."
mkdir -p "$ICONS_DIR/svg"

# Список используемых иконок из проекта
ICONS=(
    "activity" "server" "home" "settings" "bar-chart-2" "wallet" "trending-up"
    "network" "cpu" "box" "file-text" "package" "log-out" "log-in" "user"
    "sun" "moon" "menu" "chevron-down" "chevron-left" "chevron-right"
    "plus" "refresh-cw" "trash-2" "edit" "download" "copy" "check" "check-circle"
    "alert-circle" "alert-triangle" "info" "search" "globe" "database" "lock"
    "key" "tag" "map-pin" "building" "calendar" "clock" "dollar-sign" "link"
    "image" "pause" "scan" "maximize-2" "hard-drive" "list-ordered"
)

echo "📥 Скачивание ${#ICONS[@]} иконок..."
for icon in "${ICONS[@]}"; do
    echo "  - $icon"
    curl -L "https://unpkg.com/lucide@${LUCIDE_VERSION}/dist/icons/${icon}.svg" -o "$ICONS_DIR/svg/${icon}.svg" 2>/dev/null || echo "    ⚠️ Иконка $icon не найдена"
done

echo ""
echo "✅ Иконки скачаны в: $ICONS_DIR"
echo "📦 lucide.min.js: $ICONS_DIR/lucide.min.js"
echo "🎨 SVG иконки: $ICONS_DIR/svg/"
echo ""
echo "💡 Теперь обновите monitoring/includes/layout.php:"
echo "   Замените: <script src=\"https://unpkg.com/lucide@latest\"></script>"
echo "   На: <script src=\"/frontend/icons/lucide/lucide.min.js\"></script>"

