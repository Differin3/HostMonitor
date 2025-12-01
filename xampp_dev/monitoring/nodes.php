<?php
require_once __DIR__ . '/includes/layout.php';

$actions = '<button class="primary" type="button" onclick="addNode()"><i data-lucide="plus"></i> Добавить ноду</button>';
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
                    <th>Биллинг</th>
                    <th>Следующий платеж</th>
                    <th>Статус</th>
                    <th>Uptime</th>
                    <th>Ping</th>
                    <th><i data-lucide="settings"></i> Действия</th>
                </tr>
            </thead>
            <tbody id="nodes-tbody"></tbody>
        </table>
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
                        <label class="form-label">Провайдер</label>
                        <div class="input-with-icon">
                            <i data-lucide="building" class="input-icon"></i>
                            <select name="provider_name" id="provider-select-create" class="provider-select">
                                <option value="">Выберите провайдера</option>
                            </select>
                        </div>
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
                        <label class="form-label">Провайдер</label>
                        <div class="input-with-icon">
                            <i data-lucide="building" class="input-icon"></i>
                            <select name="provider_name" id="provider-select-edit" class="provider-select">
                                <option value="">Выберите провайдера</option>
                            </select>
                        </div>
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
            <button class="context-action-btn context-action-enable" onclick="enableSelectedNodes()">
                <i data-lucide="check-circle"></i> Включить
            </button>
            <button class="context-action-btn context-action-disable" onclick="disableSelectedNodes()">
                <i data-lucide="x-circle"></i> Отключить
            </button>
            <button class="context-action-btn context-action-refresh" onclick="refreshSelectedNodes()">
                <i data-lucide="refresh-cw"></i> Обновить
            </button>
            <button class="context-action-btn context-action-delete" onclick="deleteSelectedNodes()">
                <i data-lucide="trash-2"></i> Удалить
            </button>
        </div>
    </div>

    <div class="toast hidden" id="toast"></div>
<?php
render_layout_end(['/frontend/js/nodes.js']);

