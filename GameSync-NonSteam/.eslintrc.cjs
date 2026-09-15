/** @type {import('eslint').Linter.Config} */
module.exports = {
  root: true,
  env: {
    browser: true,
    node: true,
    es2021: true,
  },
  parser: "@typescript-eslint/parser",
  parserOptions: {
    ecmaVersion: "latest",
    sourceType: "module",
    ecmaFeatures: {
      jsx: true,
    },
  },
  plugins: ["@typescript-eslint", "react", "react-hooks"],
  extends: [
    "eslint:recommended",
    "plugin:@typescript-eslint/recommended",
    "plugin:react/recommended",
    "plugin:react-hooks/recommended",
  ],
  rules: {
    // React 17+ JSX transform — импортировать React не нужно
    "react/react-in-jsx-scope": "off",
    // Типы проверяет TypeScript, prop-types не нужен
    "react/prop-types": "off",
    // any в местах работы с Decky API — предупреждение, а не ошибка
    "@typescript-eslint/no-explicit-any": "warn",
  },
  settings: {
    react: {
      version: "detect",
    },
  },
  ignorePatterns: [
    "dist/",
    "dist-plugin/",
    "node_modules/",
    "backend/**",
    "примеры/**",
  ],
};

