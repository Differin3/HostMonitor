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
            </div>
            <div class="modal-actions" style="border-top:1px solid var(--border); padding:16px 20px;">
                <button type="button" class="btn-outline" id="enroll-wizard-cancel">Закрыть</button>
            </div>
        </div>
    </div>