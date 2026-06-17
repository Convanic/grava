<?php
/** @var array<string,mixed> $route */
/** @var array<string,list<string>> $errors */
/** @var array{title:string,description:string,visibility:string,tags:string} $values */
/** @var string $_csrf */

$id = (string)$route['id'];

$err = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) { return ''; }
    return '<span class="field-error">' . htmlspecialchars((string)$errors[$field][0], ENT_QUOTES, 'UTF-8') . '</span>';
};
?>
<section class="card">
    <h1>Route bearbeiten</h1>
    <p class="muted">
        Geometrie ist je Version unveränderlich — eine neue Geometrie liefert die
        App über einen erneuten Upload mit derselben <code>client_route_uuid</code>.
        Hier passt du nur Metadaten an.
    </p>

    <form method="post" action="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/update" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">

        <label>
            Titel
            <input type="text" name="title" maxlength="140" required
                   value="<?= htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8') ?>">
            <?= $err('title') ?>
        </label>

        <label>
            Beschreibung <span class="muted">(optional)</span>
            <textarea name="description" rows="4" maxlength="8000"><?= htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <?= $err('description') ?>
        </label>

        <label>
            Sichtbarkeit
            <select name="visibility">
                <?php foreach (['private' => 'Privat', 'unlisted' => 'Mit Link teilbar', 'public' => 'Öffentlich (später)'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $values['visibility'] === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('visibility') ?>
        </label>

        <label>
            Tags <span class="muted">(kommagetrennt)</span>
            <input type="text" name="tags" placeholder="gravel, alps"
                   value="<?= htmlspecialchars($values['tags'], ENT_QUOTES, 'UTF-8') ?>">
            <?= $err('tags') ?>
        </label>

        <div class="form-actions">
            <button type="submit">Speichern</button>
            <a href="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="btn-link">Abbrechen</a>
        </div>
    </form>
</section>
