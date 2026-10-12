// Page hors ligne : recharge la page demandée dès que le réseau revient
// (déverrouillage du téléphone, Wi-Fi retrouvé), sans attendre un clic.
(function () {
  'use strict';
  let busy = false;
  const retry = async () => {
    if (busy || document.hidden) return;
    busy = true;
    try {
      // Le serveur répond : on recharge l'adresse d'origine (gardée par le navigateur)
      const res = await fetch('/manifest.webmanifest', { cache: 'no-store', credentials: 'same-origin' });
      if (res.ok) window.location.reload();
    } catch (e) {
      /* toujours hors ligne */
    } finally {
      busy = false;
    }
  };
  window.addEventListener('online', retry);
  document.addEventListener('visibilitychange', retry);
  window.addEventListener('pageshow', retry);
  setInterval(retry, 5000);
})();
