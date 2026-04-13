<?php
// index.php - Welcome / Landing Page
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Beulah Multi-Purpose Cooperative Society Ltd. - Modern savings and loans management system for cooperatives.">
    <title>Beulah Multi-Purpose Cooperative Society Ltd.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .landing2-feature, .landing2-steps div {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .landing2-feature:hover, .landing2-steps div:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(97, 4, 95, 0.2);
        }
    </style>
</head>
<body class="landing2-body">
    <header class="landing2-nav">
        <div class="landing2-brand">Beulah Coop</div>
        <div class="landing2-links">
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="#contact">Contact</a>
            <a href="login.php" class="btn btn-primary btn-sm" style="color:white">Login</a>
        </div>
    </header>

    <main class="landing2-hero">
        <div class="landing2-hero-text">
            <div class="landing2-eyebrow">Beulah Multi‑Purpose Cooperative</div>
            <h1>Modern savings & loans management for your cooperative.</h1>
            <p>
                Organize members, track savings and loans, import Excel ledgers, and generate
                clean reports — all in a secure, role‑based dashboard.
            </p>
            <div class="landing2-cta">
                <a href="login.php" class="btn btn-primary btn-lg">Get Started</a>
                <a href="#features" class="btn btn-outline-primary btn-lg">Explore Features</a>
            </div>
            <div class="landing2-badges">
                <span>Excel Import</span>
                <span>Charts & Reports</span>
                <span>Audit Logs</span>
            </div>
        </div>
        <div class="landing2-hero-card">
            <div class="landing2-card-top">
                <div>
                    <div class="landing2-card-label">Monthly Savings</div>
                    <div class="landing2-card-value">₦1,245,000</div>
                </div>
                <div class="landing2-card-chip">Live</div>
            </div>
            <div class="landing2-card-chart">
                <canvas id="landingChart" width="300" height="140"></canvas>
            </div>
            <div class="landing2-card-row">
                <div>
                    <small>Loans Issued</small>
                    <strong>₦620,000</strong>
                </div>
                <div>
                    <small>Repayments</small>
                    <strong>₦412,500</strong>
                </div>
            </div>
        </div>
    </main>

    <section class="landing2-section" id="features">
        <h2>Everything your cooperative needs</h2>
        <div class="landing2-grid">
            <div class="landing2-feature">
                <i class="bi bi-people-fill landing2-icon mb-2"></i>
                <h3>Member Management</h3>
                <p>Create, edit, and manage members with secure access and clear records.</p>
            </div>
            <div class="landing2-feature">
                <i class="bi bi-bar-chart-line-fill landing2-icon mb-2"></i>
                <h3>Transactions & Reports</h3>
                <p>Track savings, loans, and repayments with exportable reports.</p>
            </div>
            <div class="landing2-feature">
                <i class="bi bi-file-earmark-excel-fill landing2-icon mb-2"></i>
                <h3>Excel Ledger Import</h3>
                <p>Upload and reconcile your cooperative ledger in seconds.</p>
            </div>
        </div>
    </section>

    <section class="landing2-section landing2-how" id="how">
        <h2>How it works</h2>
        <div class="landing2-steps">
            <div>
                <i class="bi bi-cloud-upload-fill landing2-icon mb-2"></i>
                <span>01</span>
                <h4>Upload your ledger</h4>
                <p>Import member data and transactions from Excel.</p>
            </div>
            <div>
                <i class="bi bi-gear-fill landing2-icon mb-2"></i>
                <span>02</span>
                <h4>Manage accounts</h4>
                <p>Monitor savings, loans, and repayments in real time.</p>
            </div>
            <div>
                <i class="bi bi-download-fill landing2-icon mb-2"></i>
                <span>03</span>
                <h4>Export reports</h4>
                <p>Download reports for meetings and auditing.</p>
            </div>
        </div>
    </section>

    <section class="landing2-cta-band" id="contact">
        <div>
            <h2>Ready to get started?</h2>
            <p>Login to manage members and transactions in one secure place.</p>
        </div>
        <a href="login.php" class="btn btn-primary btn-lg">Go to Login</a>
    </section>

    <footer class="landing2-footer">
        <small>&copy; <?= date('Y') ?> Beulah Multi‑Purpose Cooperative Society Ltd.</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Initialize landing page chart
        const ctx = document.getElementById('landingChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Monthly Savings',
                        data: [950000, 1020000, 1100000, 1180000, 1220000, 1245000],
                        borderColor: '#61045F',
                        backgroundColor: 'rgba(97, 4, 95, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#61045F',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₦' + context.raw.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function(value) {
                                    return '₦' + (value / 1000).toFixed(0) + 'k';
                                }
                            },
                            grid: { display: false }
                        },
                        x: {
                            grid: { display: false }
                        }
                    },
                    elements: {
                        point: { radius: 4 }
                    }
                }
            });
        }
    </script>
</body>
</html>
