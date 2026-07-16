// ============================================
// E-Pengendalian - Script.js
// ============================================

// Toggle sidebar mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('active');
}

// Konfirmasi hapus
function confirmDelete(message) {
    return confirm(message || 'Apakah Anda yakin?');
}

// Auto-hide alert
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() { alert.remove(); }, 500);
        }, 4000);
    });
});

// Format input angka
function formatNumberInput(input) {
    input.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
}

document.getElementById('sidebarToggle')?.addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('active');
});

// ============================================
// E-Pengendalian - Script.js (Responsive)
// ============================================

// Toggle sidebar untuk mobile
function initSidebarToggle() {
    const hamburger = document.querySelector('.hamburger');
    const sidebar = document.querySelector('.sidebar');
    if (hamburger && sidebar) {
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
        });
        // Tutup sidebar jika klik di luar (opsional)
        document.addEventListener('click', function(event) {
            if (!sidebar.contains(event.target) && !hamburger.contains(event.target) && sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });
    }
}

// Konfirmasi hapus
function confirmDelete(message) {
    return confirm(message || 'Apakah Anda yakin ingin menghapus data ini?');
}

// Auto-hide alert setelah 4 detik
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() { 
                if(alert.parentNode) alert.remove(); 
            }, 500);
        }, 4000);
    });
    
    // Inisialisasi sidebar toggle
    initSidebarToggle();
    
    // Format input angka (hilangkan non-digit)
    const angkaInputs = document.querySelectorAll('input[type="text"][oninput*="formatNumberInput"]');
    angkaInputs.forEach(function(input) {
        input.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    });
});

