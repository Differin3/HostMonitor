<?php
require_once __DIR__ . '/includes/layout.php';

$actions = '<button class="primary" type="button" onclick="addNode()"><i data-lucide="plus"></i> Создать ноду</button>'
    . ' <button class="btn-outline" type="button" onclick="openEnrollWizard()"><i data-lucide="link"></i> Подключить ноду (Enroll)</button>'
    . ' <button class="btn-outline" type="button" onclick="exportNodes()" title="Экспорт списка нод"><i data-lucide="download"></i> Экспорт</button>'
    . ' <label class="btn-outline" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px" title="Импорт нод из JSON"><i data-lucide="upload"></i> Импорт'
    . '<input type="file" id="nodes-import-file" accept=".json" style="display:none" onchange="importNodesFile(this)"></label>';
render_layout_start('Ноды', 'nodes', $actions);
?>
    <div class="page-controls">
        <div class="search-control">
            <input type="text" id="nodes-search" placeholder="Поиск по нодам..." />
        </div>
        <div class="sort-control">
            <select id="nodes-sort">
                <option value="name">По имени</option>
                <option value="host">По хосту</option>
                <option value="status">По статусу</option>
                <option value="billing">По биллингу</option>
            </select>
        </div>
    </div>
    <div class="table-container">
        <table id="nodes-table">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="select-all-nodes" title="Выбрать все"></th>
                    <th>ID</th>
                    <th>Имя</th>
                    <th>Хост</th>
                    <th>Провайдер</th>
                    <th>Статус</th>
                    <th>Uptime</th>
                    <th>Ping</th>
                    <th><i data-lucide="cpu"></i> CPU</th>
                    <th>Агент</th>
                    <th><i data-lucide="settings"></i></th>
                </tr>
            </thead>
            <tbody id="nodes-tbody"></tbody>
        </table>
        <div class="table-loader hidden" id="nodes-loader">
            <span class="loader-spinner"></span>
            <p>Загрузка данных...</p>
        </div>
    </div>

    <!-- Модальное окно создания ноды -->
    <div class="modal hidden" id="node-create-modal">
        <div class="modal-dialog" style="max-width: 600px;">
            <div class="modal-header">
                <h2>Создать ноду</h2>
                <button class="modal-close" id="node-create-close" type="button" aria-label="Закрыть"><i data-lucide="x"></i></button>
            </div>

            <form id="node-create-form">
                <div class="form-field">
                    <label class="form-label">Внутреннее имя *</label>
                    <div class="input-with-icon">
                        <i data-lucide="tag" class="input-icon"></i>
                        <input type="text" name="name" required placeholder="например, RU-MSK-Узел-01">
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Домен или IP-адрес *</label>
                    <div class="input-with-icon">
                        <i data-lucide="globe" class="input-icon"></i>
                        <input type="text" name="host" required placeholder="192.168.1.1">
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Провайдер <span class="form-optional">необязательно</span></label>
                    <div class="input-with-icon">
                        <i data-lucide="building" class="input-icon"></i>
                        <select name="provider_name" id="provider-select-create" class="provider-select">
                            <option value="">Нет / домашняя лаборатория</option>
                        </select>
                    </div>
                    <small class="form-hint-text">Для home lab можно оставить пустым</small>
                </div>

                <div class="modal-note">
                    <i data-lucide="link"></i>
                    <span>После создания откроется команда подключения (Enroll, Ed25519). Приватный ключ генерирует агент, панель его не раздаёт.</span>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-outline" id="node-create-cancel">Отмена</button>
                    <button type="submit" class="primary">
                        <i data-lucide="check"></i> Создать ноду
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно редактирования ноды -->
    <div class="modal hidden" id="node-edit-modal">
        <div class="modal-dialog" style="max-width: 600px;">
            <div class="modal-header">
                <h2>Редактировать ноду</h2>
                <button class="modal-close" id="node-edit-close" type="button" aria-label="Закрыть"><i data-lucide="x"></i></button>
            </div>

            <form id="node-edit-form">
                <div class="form-field">
                    <label class="form-label">Внутреннее имя *</label>
                    <div class="input-with-icon">
                        <i data-lucide="tag" class="input-icon"></i>
                        <input type="text" name="name" required>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Страна</label>
                    <div class="input-with-icon">
                        <i data-lucide="map-pin" class="input-icon"></i>
                        <select name="country" class="country-select">
                            <option value="">Выберите страну</option>
                            <option value="RU">🇷🇺 Россия</option>
                            <option value="NL">🇳🇱 Нидерланды</option>
                            <option value="DE">🇩🇪 Германия</option>
                            <option value="US">🇺🇸 США</option>
                            <option value="GB">🇬🇧 Великобритания</option>
                            <option value="FR">🇫🇷 Франция</option>
                            <option value="PL">🇵🇱 Польша</option>
                            <option value="FI">🇫🇮 Финляндия</option>
                            <option value="SG">🇸🇬 Сингапур</option>
                            <option value="JP">🇯🇵 Япония</option>
                            <option value="CN">🇨🇳 Китай</option>
                            <option value="IN">🇮🇳 Индия</option>
                            <option value="BR">🇧🇷 Бразилия</option>
                            <option value="CA">🇨🇦 Канада</option>
                            <option value="AU">🇦🇺 Австралия</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Домен или IP-адрес *</label>
                    <div class="input-with-icon">
                        <i data-lucide="globe" class="input-icon"></i>
                        <input type="text" name="host" required>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Провайдер <span class="form-optional">необязательно</span></label>
                    <div class="input-with-icon">
                        <i data-lucide="building" class="input-icon"></i>
                        <select name="provider_name" id="provider-select-edit" class="provider-select">
                            <option value="">Нет / домашняя лаборатория</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label">Конфигурация агента</label>
                    <div class="ha-actions">
                        <button type="button" class="btn-copy-config-full" onclick="copyConfig('edit')">
                            <i data-lucide="copy"></i> Копировать конфиг
                        </button>
                        <button type="button" class="btn-copy-config-full" onclick="exportNodeConfig(document.getElementById('node-edit-form').dataset.nodeId)" id="export-config-btn">
                            <i data-lucide="download"></i> Экспорт конфига
                        </button>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-outline" id="node-edit-cancel">Отмена</button>
                    <button type="submit" class="primary">
                        <i data-lucide="check"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Контекстное меню действий -->
    <div class="context-menu hidden" id="nodes-context-menu">
        <div class="context-menu-header">
            <span>ВЫБРАНО: <span id="selected-count">0</span></span>
        </div>
        <div class="context-menu-actions">
            <button class="context-action-link" onclick="clearSelection()">Очистить выбор</button>
            <button class="context-action-link" onclick="selectAllNodes()">Выбрать все</button>
            <button class="context-action-btn context-action-refresh" onclick="refreshSelectedNodes()">
                <i data-lucide="refresh-cw"></i> Обновить
            </button>
            <button class="context-action-btn" onclick="checkAgentUpdateSelected()">
                <i data-lucide="search"></i> Проверить агент
            </button>
            <button class="context-action-btn" onclick="updateAgentSelected()">
                <i data-lucide="download"></i> Обновить агент
            </button>
            <button class="context-action-btn context-action-delete" onclick="deleteSelectedNodes()">
                <i data-lucide="trash-2"></i> Удалить
            </button>
        </div>
    </div>

    <div class="toast hidden" id="toast"></div>

    <!-- Модальное окно предпросмотра импорта -->
    <div class="modal hidden" id="nodes-import-modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>Импорт нод</h2>
                <button class="modal-close" onclick="closeImportModal()" type="button" aria-label="Закрыть"><i data-lucide="x"></i></button>
            </div>
            <div class="modal-body" style="gap: 14px;">
                <p class="modal-footnote" id="import-summary" style="margin: 0;"></p>
                <div style="max-height: 320px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 12px;">
                    <table class="table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th style="width: 36px;"><input type="checkbox" id="import-check-all" checked onchange="importToggleAll(this)"></th>
                                <th>Имя</th>
                                <th>Хост</th>
                                <th>Порт</th>
                                <th>Страна</th>
                                <th>Провайдер</th>
                            </tr>
                        </thead>
                        <tbody id="import-tbody"></tbody>
                    </table>
                </div>
                <div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap;">
                    <label class="modal-check-row"><input type="radio" name="import-mode" value="skip" checked> Пропустить существующие</label>
                    <label class="modal-check-row"><input type="radio" name="import-mode" value="overwrite"> Перезаписать</label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-outline" onclick="closeImportModal()">Отмена</button>
                <button type="button" class="primary" id="import-apply-btn" onclick="applyImport()"><i data-lucide="upload"></i> Импортировать</button>
            </div>
            <div id="import-result" style="padding: 0 28px 16px; display: none;"></div>
        </div>
    </div>

    <!-- Модальное окно подключения ноды (Enroll) -->
    <div class="modal hidden" id="enroll-wizard-modal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h2>Подключить ноду (Enroll)</h2>
                <button class="modal-close" id="enroll-wizard-close" type="button" aria-label="Закрыть"><i data-lucide="x"></i></button>
            </div>

            <div class="modal-body">
                <div id="enroll-step-1" class="modal-step">
                    <p class="modal-footnote" style="margin: 0;">Сгенерируйте одноразовый код и выполните команду на сервере агента.</p>

                    <div class="modal-enroll-code" id="enroll-code">•••• — код появится после генерации</div>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn-outline" id="enroll-gen-btn"><i data-lucide="refresh-cw"></i> Сгенерировать</button>
                        <button type="button" class="btn-outline" id="enroll-copy-btn" disabled><i data-lucide="copy"></i> Копировать</button>
                    </div>

                    <div class="modal-note">
                        <i data-lucide="info"></i>
                        <span>Код живёт 15 минут, в БД хранится только его хэш и отдаётся один раз.</span>
                    </div>

                    <div>
                        <label class="modal-check-row" for="enroll-bind-node">
                            <input type="checkbox" id="enroll-bind-node"> Привязать к существующей ноде
                        </label>
                        <div style="margin-top: 10px; display: none;" id="enroll-node-wrap">
                            <select id="enroll-node-select"></select>
                        </div>
                    </div>
                </div>

                <div id="enroll-step-2" class="modal-step" style="display: none;">
                    <p class="modal-footnote" style="margin: 0;">Выполните на сервере агента:</p>
                    <div class="modal-code" id="enroll-cmd"></div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-outline" id="enroll-copy-cmd"><i data-lucide="copy"></i> Копировать команду</button>
                    </div>
                    <p class="modal-footnote" style="margin: 0;">После успешного enroll агент подключится к панели. Если нода уже создана — окно можно закрыть.</p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-outline" id="enroll-wizard-cancel">Закрыть</button>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes.js']);

