<?php
// includes/footer.php
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$basePath = $basePath ?? $scriptDir;
$leaf = strtolower(basename($basePath));
if (in_array($leaf, ['admin', 'member', 'auth', 'api', 'includes'], true)) {
    $basePath = rtrim(dirname($basePath), '/\\');
}
$basePath = rtrim($basePath, '/');
?>
<?php if (!empty($useDashboardLayout)): ?>
            </section>
            <footer class="dash-footer">
                <small>&copy; <?= date("Y") ?> Beulah Multi-Purpose Cooperative Society Ltd. All Rights Reserved.</small>
            </footer>
        </main>
    </div>
<?php else: ?>
    </div> <!-- End of container -->

    <footer class="bg-light py-4 mt-5 text-center">
        <div class="container">
            <small>&copy; <?= date("Y") ?> Beulah Multi-Purpose Cooperative Society Ltd. All Rights Reserved.</small>
        </div>
    </footer>
<?php endif; ?>

    <script src="<?= $basePath ?>/assets/js/dashboard.js"></script>
</body>
</html>
