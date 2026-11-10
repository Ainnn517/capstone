window.handleExpiredSession = response => {
  if (response.status === 401 || response.status === 419) {
    window.location.replace('/frontend/pages/index.html?session=expired');
  }
};
