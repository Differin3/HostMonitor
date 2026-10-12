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
                <button class="icon" id="node-create-close" type="button">&times;</button>
            </div>
            
            <form id="node-create-form">
                    <div class="form-field">
                        <label class="form-label">Секретный ключ (SECRET_KEY)</label>
                        <div class="input-with-icon">
                            <i data-lucide="key" class="input-icon"></i>
                            <input type="text" name="secret_key" id="secret-key-input-create" readonly class="secret-key-input">
                            <button type="button" class="btn-copy" onclick="copySecretKey('create')" title="Копировать">
                                <i data-lucide="copy"></i>
                            </button>
                            <button type="button" class="btn-refresh" onclick="generateNewKey('create')" title="Сгенерировать новый">
                                <i data-lucide="refresh-cw"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-field">
                        <label class="form-label">Внутреннее имя *</label>
                        <div class="input-with-icon">
                            <i data-lucide="tag" class="input-icon"></i>
                            <input type="text" name="name" required placeholder="например, RU-MSK-Узел-01">
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
                            <input type="text" name="host" required placeholder="192.168.1.1" value="192.168.1.1">
                        </div>
                    </div>
                    
                    <div class="form-field">
                        <label class="form-label">Порт узла *</label>
                        <div class="input-with-icon">
                            <i data-lucide="server" class="input-icon"></i>
                            <input type="number" name="port" required value="2222" min="1" max="65535">
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
                    
                    <div class="form-field">
                        <label class="form-label">Конфигурация агента</label>
                        <button type="button" class="btn-copy-config-full" onclick="copyConfig('create')">
                            <i data-lucide="copy"></i> Копировать конфиг
                        </button>
                        <div class="form-hint">
                            <i data-lucide="info"></i>
                            Конфиг будет сгенерирован после создания ноды
                    </div>
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
                <button class="icon" id="node-edit-close" type="button">&times;</button>
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
                        <label class="form-label">Порт узла *</label>
                        <div class="input-with-icon">
                            <i data-lucide="server" class="input-icon"></i>
                            <input type="number" name="port" required min="1" max="65535">
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
                        <div style="display: flex; gap: 8px;">
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
        <div class="modal-dialog" style="max-width:700px">
            <div class="modal-header">
                <h2>Импорт нод</h2>
                <button class="icon" onclick="closeImportModal()" type="button">&times;</button>
            </div>
            <div style="padding:0 24px 16px">
                <p id="import-summary" style="margin:0 0 12px;color:var(--text-secondary);font-size:13px"></p>
                <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:8px">
                    <table class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width:36px"><input type="checkbox" id="import-check-all" checked onchange="importToggleAll(this)"></th>
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
                <div style="margin-top:12px;display:flex;gap:16px;align-items:center">
                    <label style="font-size:13px"><input type="radio" name="import-mode" value="skip" checked> Пропустить существующие</label>
                    <label style="font-size:13px"><input type="radio" name="import-mode" value="overwrite"> Перезаписать</label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-outline" onclick="closeImportModal()">Отмена</button>
                <button type="button" class="primary" id="import-apply-btn" onclick="applyImport()"><i data-lucide="upload"></i> Импортировать</button>
            </div>
            <div id="import-result" style="padding:0 24px 16px;display:none"></div>
        </div>
    </div>

    <!-- Модальное окно подключения ноды (Enroll) -->
    <div class="modal hidden" id="enroll-wizard-modal">
        <div class="modal-dialog" style="max-width: 640px;">
            <div class="modal-header">
                <h2>Подключить ноду (Enroll)</h2>
                <button class="icon" id="enroll-wizard-close" type="button">&times;</button>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:16px;">
                <div id="enroll-step-1">
                    <p style="margin:0; color:var(--text-secondary); font-size:13px">Сгенерируйте одноразовый код для подключения агента к панели.</p>
                    <div style="display:flex; gap:12px; margin-top:12px; align-items:center;">
                        <input type="text" id="enroll-code" readonly style="flex:1; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg-elev); color:var(--text-primary); font-family: monospace; font-size:14px; letter-spacing:2px; text-align:center;" placeholder="AAAA-BBBB">
                        <button type="button" class="btn-outline" id="enroll-gen-btn"><i data-lucide="refresh-cw"></i> Сгенерировать</button>
                        <button type="button" class="btn-outline" id="enroll-copy-btn" disabled><i data-lucide="copy"></i> Копировать</button>
                    </div>
                    <div style="margin-top:12px; color:var(--text-tertiary); font-size:12px;">
                        Код живёт 15 минут, хранится только хэш в БД. Отдаётся один раз.
                    </div>
                    <div style="margin-top:12px;">
                        <label style="font-size:13px; color:var(--text-secondary); display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="enroll-bind-node"> Привязать к существующей ноде
                        </label>
                        <div style="margin-top:8px; display:none;" id="enroll-node-wrap">
                            <select id="enroll-node-select" style="width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg-elev); color:var(--text-primary);">
                            </select>
                        </div>
                    </div>
                </div>
                <div id="enroll-step-2" style="display:none; border-top:1px solid var(--border); padding-top:16px;">
                    <p style="margin:0 0 8px 0; color:var(--text-secondary); font-size:13px">Выполните на сервере агента:</p>
                    <div style="background:var(--bg-elev); border:1px solid var(--border); border-radius:10px; padding:12px; font-family: monospace; font-size:12px; color:var(--text-primary); overflow-x:auto; white-space:pre-wrap;" id="enroll-cmd"></div>
                    <div style="margin-top:8px; display:flex; gap:8px;">
                        <button type="button" class="btn-outline" id="enroll-copy-cmd"><i data-lucide="copy"></i> Копировать команду</button>
                    </div>
                    <div style="margin-top:10px; color:var(--text-tertiary); font-size:12px;">
                        После успешного enroll агент должен подключиться к панели. Если нода уже создана — можно закрыть окно.
                    </div>
                </div>
            </div>
            <div class="modal-actions" style="border-top:1px solid var(--border); padding:16px 20px;">
                <button type="button" class="btn-outline" id="enroll-wizard-cancel">Закрыть</button>
            </div>
        </div>
    </div>
<?php
render_layout_end(['/frontend/js/nodes.js']);

