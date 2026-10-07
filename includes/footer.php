<?php
/**
 * Page footer + layout closing. Pairs with header.php.
 */

defined('APP_INIT') or exit('No direct access.');

$pageScripts = $pageScripts ?? [];
?>
<?php if (($layout ?? 'app') === 'app' && current_user()): ?>
        </main>
        <footer class="app-footer small text-muted">
            &copy; <?= date('Y') ?> <?= e(INSTITUTION_NAME) ?> · <?= e(APP_NAME) ?> (Academic MVP)
        </footer>
    </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>

<!-- Reusable confirmation modal (used by data-confirm forms/links in main.js) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/main.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
    <script src="<?= e(url('assets/js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
