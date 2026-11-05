// ==========================================================================
// pm2 - alternativa ao systemd para manter o WORKER rodando.
//
// O pm2 so cuida do worker (processo de linha de comando). A API continua
// rodando no PHP-FPM atras do nginx/Apache: pm2 nao serve PHP pela web.
//
// Uso (na pasta do sistema, com o usuario que roda o PHP, ex.: www-data):
//   sudo npm install -g pm2
//   sudo -u www-data pm2 start deploy/pm2/ecosystem.config.js
//   sudo -u www-data pm2 save
//   sudo pm2 startup systemd -u www-data --hp /var/www   (liga no boot)
//
// Comandos: pm2 status | pm2 logs nfse-worker-1 | pm2 restart all
// Use systemd OU pm2, nunca os dois ao mesmo tempo (docs/manual-operacao.md).
// ==========================================================================

const comum = {
  script: 'bin/nfse_worker.php',
  interpreter: 'php',
  cwd: '/var/www/nfe-integracao',
  autorestart: true,
  restart_delay: 5000,
  // Espera o worker terminar a nota em andamento antes de matar (SIGTERM -> SIGKILL).
  kill_timeout: 120000,
  max_memory_restart: '256M',
  time: true,
};

module.exports = {
  apps: [
    { ...comum, name: 'nfse-worker-1' },
    { ...comum, name: 'nfse-worker-2' },
  ],
};
