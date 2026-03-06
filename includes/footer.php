        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
        // Sidebar toggle functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });

        overlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    </script>

    <?php if (isset($extra_js)) echo $extra_js; ?>
</body>
</html>
