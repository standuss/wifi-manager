(() => {
    'use strict';

    const base = (window.WFM && window.WFM.basePath) || '';
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    qa('[data-sidebar-toggle]').forEach(button => button.addEventListener('click', () => q('#sidebar')?.classList.toggle('open')));
    qa('[data-dismiss]').forEach(button => button.addEventListener('click', () => button.closest('.toast')?.remove()));
    window.setTimeout(() => qa('.toast').forEach(toast => toast.remove()), 7000);

    const clock = q('[data-clock]');
    const updateClock = () => {
        if (clock) clock.textContent = new Intl.DateTimeFormat('cs-CZ', {dateStyle: 'medium', timeStyle: 'short'}).format(new Date());
    };
    updateClock();
    window.setInterval(updateClock, 30000);

    qa('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm || 'Opravdu chcete pokračovat?')) event.preventDefault();
    }));

    qa('[data-table-search]').forEach(input => input.addEventListener('input', () => {
        const table = document.getElementById(input.dataset.tableSearch);
        const needle = input.value.trim().toLocaleLowerCase('cs');
        if (!table) return;
        qa('[data-search-row]', table).forEach(row => {
            row.hidden = needle !== '' && !row.textContent.toLocaleLowerCase('cs').includes(needle);
        });
    }));

    const dialog = q('#register-dialog');
    qa('[data-open-register]').forEach(button => button.addEventListener('click', () => {
        if (!dialog) return;
        q('[data-register-mac]', dialog).value = button.dataset.mac || '';
        q('[data-register-device]', dialog).value = button.dataset.device || 'Telefon';
        dialog.showModal();
        q('input[name="person_name"]', dialog)?.focus();
    }));
    qa('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
    dialog?.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    const networkDialog = q('#network-dialog');
    q('[data-open-network]')?.addEventListener('click', () => networkDialog?.showModal());
    networkDialog?.addEventListener('click', event => {
        if (event.target === networkDialog) networkDialog.close();
    });
    const registrationNetwork = q('[data-registration-network]', networkDialog || document);
    const networkVlan = q('[data-network-vlan]', networkDialog || document);
    const networkVlanLabel = q('[data-network-vlan-label]', networkDialog || document);
    const updateNetworkVlan = () => {
        if (!registrationNetwork || !networkVlan) return;
        const registration = registrationNetwork.checked;
        networkVlan.value = registration ? networkVlan.dataset.registrationVlan : networkVlan.dataset.approvedVlan;
        networkVlan.readOnly = registration;
        if (networkVlanLabel) networkVlanLabel.textContent = registration ? 'Registrační VLAN ID' : 'Provozní VLAN ID';
    };
    registrationNetwork?.addEventListener('change', updateNetworkVlan);
    updateNetworkVlan();

    qa('[data-toggle-password]').forEach(button => button.addEventListener('click', () => {
        const input = button.closest('.password-field')?.querySelector('input');
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.classList.toggle('revealed', show);
        button.setAttribute('aria-label', show ? 'Skrýt heslo' : 'Zobrazit heslo');
        button.title = show ? 'Skrýt heslo' : 'Zobrazit heslo';
    }));

    const revealedPasswords = new WeakMap();
    qa('[data-network-password]').forEach(button => button.addEventListener('click', async () => {
        const row = button.closest('.network-password');
        const value = q('[data-network-password-value]', row);
        if (!row || !value || button.disabled) return;

        if (button.classList.contains('revealed')) {
            value.textContent = '••••••••';
            button.classList.remove('revealed');
            button.setAttribute('aria-label', 'Zobrazit heslo');
            button.title = 'Zobrazit heslo';
            return;
        }

        const cached = revealedPasswords.get(button);
        if (typeof cached === 'string') {
            value.textContent = cached;
            button.classList.add('revealed');
            button.setAttribute('aria-label', 'Skrýt heslo');
            button.title = 'Skrýt heslo';
            return;
        }

        button.disabled = true;
        row.classList.add('loading');
        try {
            const body = new URLSearchParams({
                _csrf: (window.WFM && window.WFM.csrf) || '',
                id: button.dataset.networkPassword || ''
            });
            const response = await fetch(`${base}/networks/password`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}
            });
            const data = await response.json();
            if (!response.ok || !data.ok || typeof data.password !== 'string') {
                throw new Error(data.message || 'Heslo se nepodařilo načíst.');
            }
            revealedPasswords.set(button, data.password);
            value.textContent = data.password;
            button.classList.add('revealed');
            button.setAttribute('aria-label', 'Skrýt heslo');
            button.title = 'Skrýt heslo';
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Heslo se nepodařilo načíst.');
        } finally {
            button.disabled = false;
            row.classList.remove('loading');
        }
    }));

    const statusLabels = {
        registered: 'Registrovaný', pending: 'Čeká na registraci', incomplete: 'Neúplná registrace', blocked: 'Blokovaný'
    };

    const cell = (tag = 'td', className = '') => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        return el;
    };
    const text = (tag, value, className = '') => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        el.textContent = value || '';
        return el;
    };

    const clientRow = client => {
        const row = document.createElement('tr');
        row.dataset.searchRow = '';
        const name = client.person_name || client.device_name || client.hostname || 'Neznámé zařízení';
        const status = client.registration_status || 'pending';

        const deviceTd = cell();
        const device = text('div', '', 'device-cell');
        const avatar = text('span', '', `device-avatar ${status}`);
        avatar.textContent = '●';
        const deviceMeta = document.createElement('div');
        deviceMeta.append(text('strong', name), text('small', client.mac_address || ''));
        device.append(avatar, deviceMeta); deviceTd.append(device); row.append(deviceTd);

        const ipTd = cell();
        ipTd.append(text('span', client.ip_address || '—', 'mono ip-value'), text('small', client.hostname || '', 'subline'));
        row.append(ipTd);

        const networkTd = cell();
        const network = text('div', '', 'network-cell');
        const wifi = text('span', '◉'); wifi.style.color = '#1f6feb';
        const networkMeta = document.createElement('div');
        networkMeta.append(text('strong', client.ssid || '—'), text('small', client.vlan_id ? `VLAN ${client.vlan_id}` : 'VLAN nezjištěna'));
        network.append(wifi, networkMeta); networkTd.append(network); row.append(networkTd);

        const apTd = cell(); apTd.append(text('strong', client.access_point_name || '—'), text('small', client.band || '', 'subline')); row.append(apTd);
        const signalTd = cell();
        const signalValue = client.signal_dbm === null || client.signal_dbm === undefined ? null : Number(client.signal_dbm);
        const signalClass = signalValue === null ? 'muted' : signalValue >= -60 ? 'good' : signalValue >= -70 ? 'ok' : signalValue >= -80 ? 'warn' : 'bad';
        const signal = text('div', '', `signal-cell ${signalClass}`);
        const bars = text('span', '', 'signal-bars'); bars.innerHTML = '<i></i><i></i><i></i><i></i>';
        signal.append(bars, text('strong', signalValue === null ? '—' : `${signalValue} dBm`)); signalTd.append(signal); row.append(signalTd);
        const statusTd = cell();
        const badge = text('span', '', `badge ${status}`); badge.append(document.createElement('i'), document.createTextNode(statusLabels[status] || status));
        statusTd.append(badge); row.append(statusTd);
        return row;
    };

    const liveTable = q('[data-live-clients]');
    let refreshRunning = false;
    const refreshLive = async () => {
        if (!liveTable || refreshRunning || document.hidden) return;
        refreshRunning = true;
        try {
            const response = await fetch(`${base}/api/live`, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) return;
            const data = await response.json();
            const body = q('tbody', liveTable);
            if (body) {
                body.replaceChildren();
                if (!data.clients.length) {
                    const row = document.createElement('tr'); row.className = 'empty-row';
                    const td = cell(); td.colSpan = 6; td.append(text('strong', 'Žádná připojená zařízení')); row.append(td); body.append(row);
                } else data.clients.slice(0, 12).forEach(client => body.append(clientRow(client)));
            }
            const countClients = q('[data-count-clients]'); if (countClients) countClients.textContent = String(data.counts.clients);
            const countPending = q('[data-count-pending]'); if (countPending) countPending.textContent = String(data.counts.pending);
            qa('[data-pending-count]').forEach(badge => { badge.textContent = String(data.counts.pending); badge.hidden = data.counts.pending === 0; });
            const lastSync = q('[data-last-sync]');
            if (lastSync && data.router?.last_sync_at) lastSync.textContent = data.router.last_sync_at;
        } catch (_) {
            // Poslední známý stav zůstává zobrazený; dostupnost hlídá synchronizační služba.
        } finally {
            refreshRunning = false;
        }
    };
    if (liveTable) {
        window.setInterval(refreshLive, 3000);
        window.setTimeout(refreshLive, 800);
    }
})();
