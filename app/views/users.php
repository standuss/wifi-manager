<div class="two-column-layout users-layout">
<section class="panel">
    <header class="panel-header"><div><span class="panel-kicker">PŘÍSTUPY A OZNÁMENÍ</span><h2>Účty administrace</h2><p>Každý uživatel může mít vlastní e-mail a výběr událostí, které chce dostávat.</p></div></header>
    <div class="user-list">
    <?php foreach ($users as $user): ?>
        <details class="user-editor <?= $user['active'] ? '' : 'inactive' ?>">
            <summary>
                <span class="avatar large"><?= e(mb_strtoupper(mb_substr($user['display_name'], 0, 1))) ?></span>
                <span class="user-summary"><strong><?= e($user['display_name']) ?></strong><span>@<?= e($user['username']) ?><?= $user['email'] ? ' · ' . e($user['email']) : ' · bez e-mailu' ?></span><small>Poslední přihlášení: <?= e(format_datetime($user['last_login_at'])) ?></small></span>
                <span class="role-badge <?= e($user['role']) ?>"><?= $user['role'] === 'admin' ? 'Administrátor' : 'Prohlížení' ?></span>
            </summary>
            <form method="post" action="<?= e(url('/users/update')) ?>" class="user-edit-form">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <label><span>Zobrazované jméno</span><input name="display_name" value="<?= e($user['display_name']) ?>" required maxlength="120"></label>
                <label><span>E-mail pro oznámení</span><input type="email" name="email" value="<?= e($user['email']) ?>" maxlength="254" placeholder="uzivatel@example.cz"></label>
                <label><span>Oprávnění</span><select name="role"><option value="viewer" <?= $user['role'] === 'viewer' ? 'selected' : '' ?>>Pouze prohlížení</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrátor</option></select></label>
                <div class="notification-choices">
                    <strong>Posílat e-mailem</strong>
                    <label><input type="checkbox" name="notify_new_device" value="1" <?= $user['notify_new_device'] ? 'checked' : '' ?>> Nové zařízení v síti</label>
                    <label><input type="checkbox" name="notify_backup_result" value="1" <?= $user['notify_backup_result'] ? 'checked' : '' ?>> Výsledek zálohy MikroTiku</label>
                    <label><input type="checkbox" name="notify_monitoring_problem" value="1" <?= $user['notify_monitoring_problem'] ? 'checked' : '' ?>> Výpadek synchronizace routeru</label>
                </div>
                <div class="user-editor-actions"><button class="button primary small" type="submit">Uložit uživatele</button></div>
            </form>
            <form method="post" action="<?= e(url('/users/toggle')) ?>" class="user-toggle" data-confirm="Opravdu chcete změnit stav tohoto účtu?">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="button subtle small" <?= (int) $user['id'] === (int) $auth->user()['id'] ? 'disabled' : '' ?>><?= $user['active'] ? 'Deaktivovat účet' : 'Aktivovat účet' ?></button>
            </form>
        </details>
    <?php endforeach; ?>
    </div>
</section>
<aside class="panel side-panel">
    <header class="panel-header"><div><span class="panel-kicker">NOVÝ PŘÍSTUP</span><h2>Přidat uživatele</h2></div></header>
    <form method="post" action="<?= e(url('/users')) ?>" class="stacked-form new-user-form">
        <?= csrf_field() ?>
        <label><span>Zobrazované jméno</span><input name="display_name" required maxlength="120"></label>
        <label><span>Přihlašovací jméno</span><input name="username" required minlength="3" maxlength="50" autocomplete="off"></label>
        <label><span>E-mail pro oznámení</span><input type="email" name="email" maxlength="254"></label>
        <label><span>Heslo</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label><span>Oprávnění</span><select name="role"><option value="viewer">Pouze prohlížení</option><option value="admin">Administrátor</option></select></label>
        <div class="notification-choices compact"><strong>Posílat e-mailem</strong><label><input type="checkbox" name="notify_new_device" value="1"> Nová zařízení</label><label><input type="checkbox" name="notify_backup_result" value="1"> Zálohy</label><label><input type="checkbox" name="notify_monitoring_problem" value="1"> Výpadky routeru</label></div>
        <button class="button primary wide" type="submit"><?= icon('plus') ?> Vytvořit účet</button>
    </form>
</aside>
</div>
