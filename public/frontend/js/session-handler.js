window.handleExpiredSession = response => {
  if (response.status === 401 || response.status === 419) {
    window.location.replace('/?session=expired');
  }
};
