
<?php echo $__env->make('errors.partials.page', [
    'status' => $exception?->getStatusCode() ?? 400,
], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/errors/4xx.blade.php ENDPATH**/ ?>