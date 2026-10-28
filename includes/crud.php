<?php
/*
|--------------------------------------------------------------------------
| includes/crud.php  -  one engine for the simple "list + add / edit / delete" screens
|--------------------------------------------------------------------------
| Banners, coupons, categories, pages, FAQs ... are all described by a small
| array (table, columns, fields). To add a new simple screen you only write that array.
| See web-banners.php for the smallest example.
*/

/** save an uploaded image into uploads/<folder>/ ; returns file name, '' when nothing uploaded. Throws RuntimeException on a bad file. */
function crm_upload_image(string $input, string $folder, int $maxMb = 4): string
{
    if (empty($_FILES[$input]) || ($_FILES[$input]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $f = $_FILES[$input];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error ' . (int)$f['error'] . ').');
    }
    if ($f['size'] > $maxMb * 1048576) {
        throw new RuntimeException('Image is too big (maximum ' . $maxMb . ' MB).');
    }
    $info = @getimagesize($f['tmp_name']);
    $ext  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? '';
    if (!$info || $ext === '') {
        throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }
    $dir = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create the upload folder.');
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the image.');
    }
    return $name;
}

function crm_delete_upload(string $folder, string $file): void
{
    if ($file !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
        @unlink(dirname(__DIR__) . '/uploads/' . $folder . '/' . $file);
    }
}

/** make sure the CRM URL is known (images are served from <crm>/uploads) */
function crm_web_urls_autoset(): void
{
    if (wc_setting('web_crm_url') === '') {
        $dir = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
        wc_set('web_crm_url', (crm_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir);
    }
}

function crud_page(array $c): void
{
    require_once __DIR__ . '/layout.php';
    require_once __DIR__ . '/webcore/load.php';
    crm_web_urls_autoset();
    $table  = $c['table'];
    $folder = $c['folder'] ?? 'web';
    $fields = $c['fields'];
    $self   = basename((string)$_SERVER['SCRIPT_NAME']);
    $canAdd = $c['allow_add'] ?? true;
    $canDel = $c['allow_delete'] ?? true;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        crm_csrf_require();
        $do = (string)($_POST['do'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($do === 'save') {
                $old  = $id > 0 ? crm_one("SELECT * FROM `$table` WHERE id = ?", 'i', [$id]) : null;
                if ($id > 0 && !$old) {
                    throw new RuntimeException('Record not found.');
                }
                if ($id === 0 && !$canAdd) {
                    throw new RuntimeException('Adding is not allowed here.');
                }
                $data = [];
                $newFiles = [];
                foreach ($fields as $f) {
                    $n = $f['name'];
                    if (!empty($f['readonly_on_edit']) && $old) {
                        continue;
                    }
                    $t = $f['type'] ?? 'text';
                    if ($t === 'image') {
                        $up = crm_upload_image($n, $folder);
                        if ($up !== '') {
                            $newFiles[$n] = $up;
                            $data[$n] = $up;
                        } elseif (!empty($_POST[$n . '__clear']) && $old) {
                            $data[$n] = '';
                        }
                        continue;
                    }
                    if ($t === 'checkbox') {
                        $data[$n] = !empty($_POST[$n]) ? 1 : 0;
                        continue;
                    }
                    $v = trim((string)($_POST[$n] ?? ''));
                    if (!empty($f['upper'])) {
                        $v = strtoupper($v);
                    }
                    if (!empty($f['required']) && $v === '') {
                        throw new RuntimeException('"' . $f['label'] . '" is required.');
                    }
                    if (isset($f['max']) && mb_strlen($v) > $f['max']) {
                        throw new RuntimeException('"' . $f['label'] . '" is too long (maximum ' . $f['max'] . ' characters).');
                    }
                    if ($t === 'number' && $v !== '' && !is_numeric($v)) {
                        throw new RuntimeException('"' . $f['label'] . '" must be a number.');
                    }
                    if ($t === 'number' && $v !== '' && isset($f['min']) && (float)$v < $f['min']) {
                        throw new RuntimeException('"' . $f['label'] . '" cannot be less than ' . $f['min'] . '.');
                    }
                    if ($t === 'date') {
                        $d = DateTime::createFromFormat('!Y-m-d', $v);
                        if ($v !== '' && (!$d || $d->format('Y-m-d') !== $v)) {
                            throw new RuntimeException('"' . $f['label'] . '" is not a valid date.');
                        }
                    }
                    if ($t === 'select' && !array_key_exists($v, $f['options'])) {
                        throw new RuntimeException('Please choose a valid "' . $f['label'] . '".');
                    }
                    if ($t === 'color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                        $v = $f['default'] ?? '#ffffff';
                    }
                    $data[$n] = ($v === '' && ($t === 'date' || !empty($f['nullable']))) ? null : $v;
                }
                if (!empty($c['before_save'])) {
                    $err = $c['before_save']($data, $old);
                    if (is_string($err) && $err !== '') {
                        foreach ($newFiles as $nf) {
                            crm_delete_upload($folder, $nf);
                        }
                        throw new RuntimeException($err);
                    }
                }
                try {
                    if ($old) {
                        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                        if ($data) {
                            crm_run("UPDATE `$table` SET $set WHERE id = ?", str_repeat('s', count($data)) . 'i', array_merge(array_values($data), [$id]));
                        }
                        foreach ($newFiles as $n => $_) {
                            crm_delete_upload($folder, (string)$old[$n]);
                        }
                        foreach ($fields as $f) {                                  // "remove current image" ticked
                            if (($f['type'] ?? '') === 'image' && array_key_exists($f['name'], $data) && $data[$f['name']] === '') {
                                crm_delete_upload($folder, (string)$old[$f['name']]);
                            }
                        }
                    } else {
                        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                        $qs   = implode(', ', array_fill(0, count($data), '?'));
                        crm_run("INSERT INTO `$table` ($cols) VALUES ($qs)", str_repeat('s', count($data)), array_values($data));
                        $id = crm_insert_id();
                    }
                } catch (mysqli_sql_exception $e) {
                    foreach ($newFiles as $nf) {
                        crm_delete_upload($folder, $nf);
                    }
                    throw new RuntimeException($e->getCode() === 1062 ? 'This value already exists (duplicate). Please use a different one.' : 'Could not save: ' . $e->getMessage());
                }
                if (!empty($c['after_save'])) {
                    $c['after_save']($id);
                }
                crm_log('website', $old ? 'update' : 'add', $c['title'] . ' #' . $id);
                crm_flash('success', 'Saved.');
            } elseif ($do === 'delete' && $canDel) {
                $old = crm_one("SELECT * FROM `$table` WHERE id = ?", 'i', [$id]);
                if ($old) {
                    if (!empty($c['before_delete'])) {
                        $err = $c['before_delete']($old);
                        if (is_string($err) && $err !== '') {
                            throw new RuntimeException($err);
                        }
                    }
                    crm_run("DELETE FROM `$table` WHERE id = ?", 'i', [$id]);
                    foreach ($fields as $f) {
                        if (($f['type'] ?? '') === 'image') {
                            crm_delete_upload($folder, (string)$old[$f['name']]);
                        }
                    }
                    crm_log('website', 'delete', $c['title'] . ' #' . $id);
                    crm_flash('success', 'Deleted.');
                }
            } elseif ($do === 'toggle' && !empty($c['toggle'])) {
                crm_run("UPDATE `$table` SET `{$c['toggle']}` = 1 - `{$c['toggle']}` WHERE id = ?", 'i', [$id]);
            }
        } catch (RuntimeException $e) {
            crm_flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log($self . ': ' . $e->getMessage());
            crm_flash('danger', 'Could not save. Please try again.');
        }
        header('Location: ' . $self);
        exit;
    }

    $rows = crm_all("SELECT * FROM `$table` ORDER BY " . ($c['order'] ?? 'id DESC') . ' LIMIT 5000');
    $actions = $canAdd ? '<button class="btn btn-gold" type="button" data-bs-toggle="modal" data-bs-target="#crudModal" data-row=\'{}\' data-title="Add"><i class="bi bi-plus-lg me-1"></i>Add new</button>' : '';
    crm_page_start($c['title']);
    crm_page_head($c['title'], $c['sub'] ?? '', $actions);
    echo crm_flash_html();
    if (!empty($c['top_html'])) {
        echo $c['top_html'];
    }
    echo '<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table><thead><tr>';
    foreach ($c['columns'] as $col) {
        echo '<th' . (!empty($col['filter']) ? ' data-filter="' . crm_e($col['label']) . '"' : '') . '>' . crm_e($col['label']) . '</th>';
    }
    echo '<th class="act">Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $json = json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
        echo '<tr>';
        foreach ($c['columns'] as $col) {
            $val = isset($col['render']) ? $col['render']($r) : crm_e($r[$col['key']] ?? '');
            $dv  = !empty($col['filter']) ? ' data-value="' . crm_e(isset($col['value']) ? $col['value']($r) : strip_tags((string)$val)) . '"' : '';
            echo '<td' . $dv . '>' . $val . '</td>';
        }
        echo '<td class="act text-nowrap">';
        if (!empty($c['toggle'])) {
            echo '<form method="post" class="d-inline">' . crm_csrf_field() . '<input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                . '<button class="btn btn-sm btn-icon" title="Switch on / off"><i class="bi bi-toggle-' . ($r[$c['toggle']] ? 'on text-success' : 'off') . '"></i></button></form>';
        }
        foreach ($c['row_actions'] ?? [] as $ra) {
            echo $ra($r);
        }
        echo '<button class="btn btn-sm btn-icon" type="button" title="Edit" data-bs-toggle="modal" data-bs-target="#crudModal" data-title="Edit" data-row=\'' . $json . '\'><i class="bi bi-pencil"></i></button>';
        if ($canDel) {
            echo '<form method="post" class="d-inline" data-confirm="Delete this record?">' . crm_csrf_field() . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn btn-sm btn-icon text-danger" title="Delete"><i class="bi bi-trash"></i></button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    /* ---- modal form ---- */
    echo '<div class="modal fade" id="crudModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">'
        . '<form method="post" enctype="multipart/form-data" autocomplete="off">' . crm_csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="0">'
        . '<div class="modal-header"><h5 class="modal-title"><span data-fill-title></span> ' . crm_e($c['item'] ?? 'record') . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><div class="row g-3">';
    foreach ($fields as $f) {
        $t = $f['type'] ?? 'text';
        $n = crm_e($f['name']);
        $col = (int)($f['col'] ?? 6);
        echo '<div class="col-md-' . $col . '">';
        if ($t === 'checkbox') {
            echo '<div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" name="' . $n . '" id="f_' . $n . '" value="1"><label class="form-check-label" for="f_' . $n . '">' . crm_e($f['label']) . '</label></div>';
        } else {
            echo '<label class="form-label" for="f_' . $n . '">' . crm_e($f['label']) . (!empty($f['required']) ? ' <span class="text-danger">*</span>' : '') . '</label>';
            if ($t === 'textarea') {
                echo '<textarea class="form-control' . (!empty($f['code']) ? ' font-monospace' : '') . '" id="f_' . $n . '" name="' . $n . '" rows="' . (int)($f['rows'] ?? 3) . '" maxlength="' . (int)($f['max'] ?? 65000) . '"></textarea>';
            } elseif ($t === 'select') {
                echo '<select class="form-select" id="f_' . $n . '" name="' . $n . '">';
                foreach ($f['options'] as $k => $l) {
                    echo '<option value="' . crm_e($k) . '">' . crm_e($l) . '</option>';
                }
                echo '</select>';
            } elseif ($t === 'image') {
                echo '<input class="form-control" type="file" id="f_' . $n . '" name="' . $n . '" accept="image/jpeg,image/png,image/webp,image/gif">'
                    . '<div class="small text-muted mt-1" data-fill-img="' . $n . '"></div>'
                    . '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="' . $n . '__clear" id="c_' . $n . '" value="1"><label class="form-check-label small" for="c_' . $n . '">Remove current image</label></div>';
            } else {
                $type = $t === 'number' ? 'number' : ($t === 'date' ? 'date' : ($t === 'color' ? 'color' : 'text'));
                echo '<input class="form-control' . ($t === 'color' ? ' form-control-color w-100' : '') . '" type="' . $type . '" id="f_' . $n . '" name="' . $n . '"'
                    . (isset($f['max']) ? ' maxlength="' . (int)$f['max'] . '"' : '') . ($t === 'number' ? ' step="' . crm_e($f['step'] ?? '0.01') . '"' : '')
                    . (!empty($f['readonly_on_edit']) ? ' data-readonly-edit="1"' : '') . (!empty($f['placeholder']) ? ' placeholder="' . crm_e($f['placeholder']) . '"' : '') . '>';
            }
            if (!empty($f['help'])) {
                echo '<div class="form-text">' . crm_e($f['help']) . '</div>';
            }
        }
        echo '</div>';
    }
    echo '</div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-gold" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button></div></form></div></div></div>';
    $defaults = json_encode($c['defaults'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP);
    crm_page_end('<script>window.CRUD_DEFAULTS=' . $defaults . ';window.CRUD_UPLOAD="' . crm_e(rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/') . '/uploads/' . $folder . '/') . '";</script>');
}
