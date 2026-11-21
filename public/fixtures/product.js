const state = new URLSearchParams(window.location.search).get("state");
document.body.dataset.state = ["broken", "fixed", "uncertain"].includes(state)
  ? state
  : "before";
