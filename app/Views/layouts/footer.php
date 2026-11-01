<?php
declare(strict_types=1);
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<?php if (isset($pageScript) && is_string($pageScript) && $pageScript !== ''): ?>
    <script src="<?= htmlspecialchars($pageScript, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
</body>
</html>
