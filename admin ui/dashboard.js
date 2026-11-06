(() => {
  "use strict";

  const isAdminPage = document.body.dataset.dashboard === "admin";
  const DATA_KEY = "gamitshare-data-v1";
  const SESSION_KEY = isAdminPage ? "gamitshare-session-v1" : "gamitshare-member-session-v1";
  const today = new Date();
  const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
  const offsetDate = (days) => {
    const date = new Date(today);
    date.setDate(date.getDate() + days);
    return localDate(date);
  };
  const makeId = () => globalThis.crypto?.randomUUID?.() || `id-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const defaultData = () => ({
    equipment: [
      { id: "e1", name: "Cordless drill set", category: "Tools", emoji: "🛠️", description: "For those little fixes and big DIY dreams.", quantity: 3, available: 2, condition: "Good", status: "Available", photo: "" },
      { id: "e2", name: "Folding event tent", category: "Events", emoji: "⛺", description: "A little shade for the next neighborhood gathering.", quantity: 2, available: 0, condition: "Good", status: "Borrowed", photo: "" },
      { id: "e3", name: "Portable speaker", category: "Electronics", emoji: "🔊", description: "Bring people together, one playlist at a time.", quantity: 4, available: 3, condition: "Good", status: "Available", photo: "" },
      { id: "e4", name: "Community garden kit", category: "Garden", emoji: "🪴", description: "Gloves, hand trowels, and room to grow.", quantity: 8, available: 6, condition: "Good", status: "Available", photo: "" },
      { id: "e5", name: "Projector & screen", category: "Electronics", emoji: "📽️", description: "Movie nights, workshops, and bright ideas.", quantity: 1, available: 0, condition: "Fair", status: "Under maintenance", photo: "" },
      { id: "e6", name: "Folding tables", category: "Events", emoji: "🪑", description: "Sturdy tables ready for a crowd.", quantity: 6, available: 4, condition: "Good", status: "Available", photo: "" }
    ],
    requests: [
      { id: "r1", equipmentId: "e2", borrower: "Lina Reyes", email: "lina.reyes@example.org", quantity: 1, startDate: offsetDate(-2), dueDate: offsetDate(3), purpose: "Weekend community market", status: "Approved", conditionOut: "Good", createdAt: offsetDate(-5) },
      { id: "r2", equipmentId: "e1", borrower: "Noah Cruz", email: "noah.cruz@example.org", quantity: 1, startDate: offsetDate(1), dueDate: offsetDate(4), purpose: "Repairing the reading nook shelves", status: "Pending", conditionOut: "Good", createdAt: offsetDate(-1) },
      { id: "r3", equipmentId: "e3", borrower: "Maya Santos", email: "maya.santos@example.org", quantity: 1, startDate: offsetDate(-12), dueDate: offsetDate(-5), purpose: "Youth group film afternoon", status: "Overdue", conditionOut: "Good", createdAt: offsetDate(-15) },
      { id: "r4", equipmentId: "e4", borrower: "Rafi Dela Cruz", email: "rafi.dc@example.org", quantity: 2, startDate: offsetDate(-1), dueDate: offsetDate(2), purpose: "Saturday garden workday", status: "Approved", conditionOut: "Good", createdAt: offsetDate(-4) },
      { id: "r5", equipmentId: "e6", borrower: "Lina Reyes", email: "lina.reyes@example.org", quantity: 2, startDate: offsetDate(-21), dueDate: offsetDate(-17), purpose: "Community potluck", status: "Returned", conditionOut: "Good", returnCondition: "Good", createdAt: offsetDate(-25), returnedAt: offsetDate(-17) },
      { id: "r6", equipmentId: "e1", borrower: "Ivo Mercado", email: "ivo.m@example.org", quantity: 1, startDate: offsetDate(5), dueDate: offsetDate(8), purpose: "Fixing a broken fence", status: "Pending", conditionOut: "Good", createdAt: offsetDate(-1) }
    ],
    people: [
      { id: "p1", name: "Maya Santos", email: "maya.santos@example.org", role: "Administrator", joined: "2025-03-12" },
      { id: "p2", name: "Lina Reyes", email: "lina.reyes@example.org", role: "Member", joined: "2025-07-08" },
      { id: "p3", name: "Noah Cruz", email: "noah.cruz@example.org", role: "Organization officer", joined: "2025-04-20" },
      { id: "p4", name: "Rafi Dela Cruz", email: "rafi.dc@example.org", role: "Custodian", joined: "2025-05-02" },
      { id: "p5", name: "Ivo Mercado", email: "ivo.m@example.org", role: "Member", joined: "2025-08-14" }
    ],
    activity: []
  });

  let data;
  let activeUser;
  let currentSection = isAdminPage ? "overview" : "home";
  let requestFilter = "";
  let confirmCallback = null;
  const categoryIcons = { Tools: "🧰", Events: "🎪", Electronics: "🔌", Garden: "🌱", Sports: "⚽", Other: "📦" };
  const stewardRoles = ["Administrator", "Organization officer", "Custodian"];
  const isAdministrator = () => activeUser?.role === "Administrator";
  const canAccessStewardDashboard = (person) => stewardRoles.includes(person?.role);
  const get = (id) => document.getElementById(id);
  const escapeHtml = (value = "") => String(value).replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
  const formatDate = (value, options = { month: "short", day: "numeric" }) => {
    if (!value) return "—";
    const date = new Date(`${value}T12:00:00`);
    return Number.isNaN(date.getTime()) ? "—" : new Intl.DateTimeFormat("en", options).format(date);
  };
  const initials = (name = "") => name.trim().split(/\s+/).slice(0, 2).map((part) => part[0] || "").join("").toUpperCase();
  const currentUserRequests = () => data.requests.filter((request) => request.email?.toLowerCase() === activeUser.email?.toLowerCase());
  const itemFor = (request) => data.equipment.find((item) => item.id === request.equipmentId);
  const isActiveBorrowing = (request) => ["Approved", "Overdue"].includes(request.status);
  const statusClass = (status) => `status-${String(status || "").toLowerCase().replaceAll(" ", "-")}`;

  function readData() {
    try {
      const saved = localStorage.getItem(DATA_KEY);
      if (!saved) return defaultData();
      const parsed = JSON.parse(saved);
      if (!Array.isArray(parsed.equipment) || !Array.isArray(parsed.requests) || !Array.isArray(parsed.people)) {
        throw new Error("Saved GamitShare data is incomplete.");
      }
      if (!Array.isArray(parsed.activity)) parsed.activity = [];
      return parsed;
    } catch (error) {
      console.error("Could not read GamitShare data.", error);
      showToast("Saved data could not be read. Showing a fresh demo collection.", true);
      return defaultData();
    }
  }
  function readSession() {
    try {
      const stored = JSON.parse(localStorage.getItem(SESSION_KEY) || "null");
      return stored && data.people.find((person) => person.email?.toLowerCase() === stored.email?.toLowerCase());
    } catch (error) {
      console.error("Could not read the active GamitShare account.", error);
      return null;
    }
  }
  function persist(nextData = data) {
    try {
      localStorage.setItem(DATA_KEY, JSON.stringify(nextData));
      data = nextData;
      return true;
    } catch (error) {
      console.error("Could not save GamitShare data.", error);
      showToast("Changes could not be saved in this browser.", true);
      return false;
    }
  }
  function commit(change, successMessage) {
    const next = JSON.parse(JSON.stringify(data));
    try {
      change(next);
      if (!persist(next)) return false;
      renderAll();
      if (successMessage) showToast(successMessage);
      return true;
    } catch (error) {
      console.error("Could not apply the GamitShare change.", error);
      showToast("That change could not be completed. Please try again.", true);
      return false;
    }
  }
  function setSession(person) {
    activeUser = person;
    try {
      localStorage.setItem(SESSION_KEY, JSON.stringify({ name: person.name, email: person.email, role: person.role }));
    } catch (error) {
      console.error("Could not save the selected account.", error);
      showToast("This browser could not save your selected account.", true);
    }
    updateProfile();
  }
  function updateProfile() {
    if (!activeUser) return;
    const avatar = initials(activeUser.name);
    if (isAdminPage) {
      get("side-avatar").textContent = avatar;
      get("side-name").textContent = activeUser.name;
      get("side-role").textContent = activeUser.role;
      get("dashboard-role").textContent = activeUser.role === "Administrator" ? "ADMIN" : activeUser.role === "Custodian" ? "CUSTODIAN" : "OFFICER";
      get("top-avatar").textContent = avatar;
      get("top-name").textContent = activeUser.name.split(" ")[0];
      get("welcome-name").textContent = activeUser.name.split(" ")[0];
      document.querySelectorAll('[data-action="add-equipment"], [data-action="add-member"]').forEach((button) => { button.classList.toggle("d-none", !isAdministrator()); });
      get("members-link").classList.toggle("d-none", !isAdministrator());
      get("section-members").classList.toggle("d-none", !isAdministrator());
      get("top-profile").classList.toggle("d-none", !isAdministrator());
    } else {
      get("member-side-avatar").textContent = avatar;
      get("member-side-name").textContent = activeUser.name;
      get("member-top-avatar").textContent = avatar;
      get("member-top-name").textContent = activeUser.name.split(" ")[0];
      get("member-welcome").textContent = activeUser.name.split(" ")[0];
      get("borrow-email").value = activeUser.email || "";
    }
  }
  function showToast(message, isError = false) {
    const container = get("toast-container");
    if (!container) return;
    const toast = document.createElement("div");
    toast.className = "toast show";
    toast.setAttribute("role", "status");
    toast.innerHTML = `<div class="d-flex align-items-center gap-2 p-2"><span class="${isError ? "text-danger" : "text-success"}">${isError ? "!" : "✓"}</span><span>${escapeHtml(message)}</span><button type="button" class="btn-close ms-auto" aria-label="Dismiss"></button></div>`;
    toast.querySelector(".btn-close").addEventListener("click", () => toast.remove());
    container.append(toast);
    window.setTimeout(() => toast.remove(), 3500);
  }
  function addActivity(target, text, icon = "✳") {
    target.activity.unshift({ id: makeId(), text, icon, date: offsetDate(0) });
    target.activity = target.activity.slice(0, 12);
  }
  function itemCell(item) {
    return `<span class="item-cell"><span class="item-emoji">${escapeHtml(item?.emoji || "📦")}</span><span><strong>${escapeHtml(item?.name || "Equipment removed")}</strong><small>${escapeHtml(item?.category || "")}</small></span></span>`;
  }
  function showSection(section, writeHash = true) {
    const availableSections = isAdminPage ? ["overview", "equipment", "requests", "reports", ...(isAdministrator() ? ["members"] : [])] : ["home", "browse", "my-requests"];
    if (!availableSections.includes(section)) section = isAdminPage ? "overview" : "home";
    currentSection = section;
    document.querySelectorAll(".page-section").forEach((page) => page.classList.toggle("active", page.id === `section-${section}`));
    document.querySelectorAll(".side-link").forEach((link) => link.classList.toggle("active", link.dataset.section === section));
    const activeLink = document.querySelector(`.side-link[data-section="${section}"]`);
    const label = activeLink ? Array.from(activeLink.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE).map((node) => node.textContent).join("").trim() : "";
    get("breadcrumb").textContent = label.replace(/\s+\d+$/, "") || (section === "home" ? "My home" : "Overview");
    get("sidebar").classList.remove("open");
    if (writeHash && location.hash !== `#${section}`) history.pushState({ section }, "", `#${section}`);
    if (writeHash) window.scrollTo(0, 0);
    renderAll();
  }

  function updateAdminNav() {
    get("side-equipment-count").textContent = String(data.equipment.length);
    const pending = data.requests.filter((request) => request.status === "Pending").length;
    get("side-pending-count").textContent = String(pending);
    get("side-pending-count").classList.toggle("d-none", pending === 0);
  }
  function renderAdminOverview() {
    const pending = data.requests.filter((request) => request.status === "Pending");
    const active = data.requests.filter(isActiveBorrowing);
    const available = data.equipment.reduce((sum, item) => sum + Number(item.available || 0), 0);
    const total = data.equipment.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const damaged = data.equipment.filter((item) => ["Damaged", "Under maintenance"].includes(item.status) || ["Damaged", "Needs repair"].includes(item.condition)).length;
    const metrics = [
      ["▣", "Items in collection", total, `${data.equipment.length} equipment types`],
      ["✳", "Ready to borrow", available, "Items available now"],
      ["↗", "Out in the community", active.length, "Active borrowings"],
      ["◷", "Waiting for review", pending.length, "Requests to look over"]
    ];
    get("admin-metrics").innerHTML = metrics.map(([icon, label, value, note]) => `<article class="metric-card"><span class="metric-icon">${icon}</span><span><span class="metric-label">${label}</span><strong class="metric-value">${value}</strong><small class="metric-foot">${note}</small></span></article>`).join("");
    get("overview-requests").innerHTML = pending.length ? pending.slice(0, 4).map((request) => {
      const item = itemFor(request);
      return `<div class="request-preview"><span class="item-emoji">${escapeHtml(item?.emoji || "📦")}</span><span class="request-preview-copy"><strong>${escapeHtml(request.borrower)} · ${escapeHtml(item?.name || "Equipment")}</strong><small>${escapeHtml(request.purpose)} · ${formatDate(request.startDate)}</small></span><button class="table-action" type="button" data-action="approve" data-id="${escapeHtml(request.id)}">Review</button></div>`;
    }).join("") : `<div class="empty-state"><strong>All caught up.</strong>No requests need a review right now.</div>`;
    const statusCounts = ["Available", "Borrowed", "Under maintenance", "Damaged", "Reserved"].map((status) => [status, data.equipment.filter((item) => item.status === status).length]);
    get("overview-health").innerHTML = `<div class="health-list">${statusCounts.map(([status, count]) => `<div class="health-line"><span class="health-swatch"></span><span>${status}</span><strong>${count}</strong></div>`).join("")}</div><div class="small text-secondary mt-2">${damaged} item${damaged === 1 ? "" : "s"} need extra care.</div>`;
    const activity = data.activity.length ? data.activity.slice(0, 4) : [
      { text: `${pending.length} requests are ready for a review`, icon: "↗", date: offsetDate(0) },
      { text: "The community collection is looking good", icon: "✳", date: offsetDate(0) },
      { text: "Every return gives shared gear a new story", icon: "↩", date: offsetDate(0) }
    ];
    get("overview-activity").innerHTML = activity.map((entry) => `<div class="activity-row"><span class="activity-symbol">${escapeHtml(entry.icon || "✳")}</span><span class="activity-copy"><strong>${escapeHtml(entry.text)}</strong><small>Community update</small></span><span class="activity-date">${formatDate(entry.date)}</span></div>`).join("");
  }
  function renderAdminEquipment() {
    const categorySelect = get("equipment-category");
    const currentCategory = categorySelect.value;
    const categories = [...new Set(data.equipment.map((item) => item.category).filter(Boolean))].sort();
    categorySelect.innerHTML = `<option value="">All categories</option>${categories.map((category) => `<option value="${escapeHtml(category)}">${escapeHtml(category)}</option>`).join("")}`;
    if (categories.includes(currentCategory)) categorySelect.value = currentCategory;
    const query = get("equipment-search").value.trim().toLowerCase();
    const category = categorySelect.value;
    const status = get("equipment-status").value;
    const results = data.equipment.filter((item) => `${item.name} ${item.category} ${item.description}`.toLowerCase().includes(query) && (!category || item.category === category) && (!status || item.status === status));
    get("equipment-result-count").textContent = `${results.length} ${results.length === 1 ? "equipment type" : "equipment types"}`;
    get("admin-equipment-grid").innerHTML = results.length ? results.map((item) => `<article class="equipment-card"><div class="equipment-image"><span class="equipment-category">${escapeHtml(item.category)}</span><span class="equipment-state status-pill ${statusClass(item.status)}">${escapeHtml(item.status)}</span>${item.photo ? `<img src="${escapeHtml(item.photo)}" alt="${escapeHtml(item.name)}">` : `<span class="equipment-emoji" aria-hidden="true">${escapeHtml(item.emoji || categoryIcons[item.category] || "📦")}</span>`}</div><div class="equipment-body"><div class="equipment-title-line"><h3>${escapeHtml(item.name)}</h3></div><p class="equipment-description">${escapeHtml(item.description || "Ready to be shared with the community.")}</p><div class="equipment-foot"><small>${Number(item.available)} of ${Number(item.quantity)} available · ${escapeHtml(item.condition)}</small>${isAdministrator() ? `<span class="d-flex gap-1"><button class="btn btn-outline-secondary" type="button" data-action="edit-equipment" data-id="${escapeHtml(item.id)}" aria-label="Edit ${escapeHtml(item.name)}">Edit</button><button class="table-action danger" type="button" data-action="delete-equipment" data-id="${escapeHtml(item.id)}" aria-label="Remove ${escapeHtml(item.name)}">×</button></span>` : ""}</div></div></article>`).join("") : `<div class="empty-state"><strong>No equipment found.</strong>Try changing a filter or add something to the collection.</div>`;
  }
  function renderAdminRequests() {
    const pending = data.requests.filter((request) => request.status === "Pending").length;
    const overdue = data.requests.filter((request) => request.status === "Overdue" || (isActiveBorrowing(request) && request.dueDate < offsetDate(0))).length;
    const approved = data.requests.filter((request) => request.status === "Approved").length;
    get("request-summary").innerHTML = `<div class="summary-chip"><span>${pending}</span><span><strong>Waiting for a look</strong><small>Pending requests</small></span></div><div class="summary-chip"><span>${approved}</span><span><strong>Out in the community</strong><small>Approved borrowings</small></span></div><div class="summary-chip"><span style="background:#faeeeb;color:#ad6d63">${overdue}</span><span><strong>Ready to come home</strong><small>Overdue items</small></span></div>`;
    const query = get("request-search").value.trim().toLowerCase();
    const filter = get("request-status").value;
    const requests = data.requests.filter((request) => `${request.borrower} ${request.email} ${itemFor(request)?.name || ""} ${request.purpose}`.toLowerCase().includes(query) && (!filter || request.status === filter));
    get("admin-requests-table").innerHTML = requests.length ? requests.map((request) => `<tr><td>${itemCell(itemFor(request))}</td><td><span class="table-person">${escapeHtml(request.borrower)}<small>${escapeHtml(request.email)}</small></span></td><td>${formatDate(request.startDate)} – ${formatDate(request.dueDate)}<small class="d-block mt-1 text-secondary">${escapeHtml(request.purpose)}</small></td><td>${request.quantity}</td><td><span class="status-pill ${statusClass(request.status)}">${escapeHtml(request.status)}</span></td><td class="text-end">${request.status === "Pending" ? `<button class="table-action" type="button" data-action="approve" data-id="${escapeHtml(request.id)}">Approve</button> <button class="table-action danger" type="button" data-action="reject" data-id="${escapeHtml(request.id)}">Decline</button>` : request.status === "Approved" || request.status === "Overdue" ? `<button class="table-action" type="button" data-action="checkin" data-id="${escapeHtml(request.id)}">Record return</button>` : "—"}</td></tr>`).join("") : `<tr><td colspan="6" class="empty-table">No requests match this search.</td></tr>`;
  }
  function renderAdminMembers() {
    const roles = ["Administrator", "Organization officer", "Custodian", "Member"];
    get("member-summary").innerHTML = roles.map((role) => `<div class="summary-chip"><span style="background:#edf4e9;color:#628563">${data.people.filter((person) => person.role === role).length}</span><span><strong>${escapeHtml(role)}</strong><small>Community accounts</small></span></div>`).join("");
    get("admin-members-table").innerHTML = data.people.map((person) => `<tr><td><span class="table-person">${escapeHtml(person.name)}</span></td><td>${escapeHtml(person.email)}</td><td><select class="form-select role-select" data-action="change-role" data-id="${escapeHtml(person.id)}" aria-label="Role for ${escapeHtml(person.name)}">${roles.map((role) => `<option${role === person.role ? " selected" : ""}>${escapeHtml(role)}</option>`).join("")}</select></td><td>${formatDate(person.joined, { year: "numeric", month: "short", day: "numeric" })}</td><td class="text-end">${person.email.toLowerCase() === activeUser.email.toLowerCase() ? `<span class="text-secondary small">You</span>` : `<button class="table-action danger" type="button" data-action="remove-person" data-id="${escapeHtml(person.id)}" aria-label="Remove ${escapeHtml(person.name)}">Remove</button>`}</td></tr>`).join("");
  }
  function reportRequests() {
    const period = get("report-period").value;
    const cutoff = period === "all" ? "" : offsetDate(-Number(period));
    return data.requests.filter((request) => !cutoff || request.createdAt >= cutoff);
  }
  function renderReports() {
    const requests = reportRequests();
    const returned = requests.filter((request) => request.status === "Returned");
    const borrowed = requests.filter((request) => isActiveBorrowing(request));
    const overdue = data.requests.filter((request) => request.status === "Overdue" || (request.status === "Approved" && request.dueDate < offsetDate(0)));
    const units = data.equipment.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    get("report-metrics").innerHTML = [["Inventory units", units], ["Borrowings", borrowed.length + returned.length], ["Returned", returned.length], ["Overdue now", overdue.length]].map(([label, value]) => `<article class="metric-card"><span class="metric-icon">✳</span><span><span class="metric-label">${label}</span><strong class="metric-value">${value}</strong><small class="metric-foot">For selected period</small></span></article>`).join("");
    const usage = data.equipment.map((item) => ({ name: item.name, count: requests.filter((request) => request.equipmentId === item.id).length })).sort((a, b) => b.count - a.count).slice(0, 5);
    const max = Math.max(1, ...usage.map((row) => row.count));
    get("usage-chart").innerHTML = usage.length ? `<div class="chart-list">${usage.map((row) => `<div class="chart-row"><span class="chart-name">${escapeHtml(row.name)}</span><span class="chart-track"><span class="chart-bar" style="display:block;width:${Math.max(3, row.count / max * 100)}%"></span></span><span class="chart-value">${row.count}</span></div>`).join("")}</div>` : `<div class="empty-state">No borrowing activity yet.</div>`;
    const statuses = ["Available", "Borrowed", "Under maintenance", "Damaged", "Reserved"];
    get("status-chart").innerHTML = `<div class="status-breakdown">${statuses.map((status) => `<div class="status-row"><span class="health-swatch"></span><span>${status}</span><strong>${data.equipment.filter((item) => item.status === status).length}</strong></div>`).join("")}</div>`;
    get("overdue-table").innerHTML = overdue.length ? overdue.map((request) => {
      const daysLate = Math.max(1, Math.ceil((today - new Date(`${request.dueDate}T00:00:00`)) / 86400000));
      return `<tr><td>${itemCell(itemFor(request))}</td><td>${escapeHtml(request.borrower)}</td><td>${formatDate(request.dueDate)}</td><td>${daysLate} days</td><td>${escapeHtml(request.email)}</td></tr>`;
    }).join("") : `<tr><td colspan="5" class="empty-table">Nothing overdue. Thank you, neighbors!</td></tr>`;
  }
  function renderAdmin() {
    updateAdminNav();
    renderAdminOverview();
    renderAdminEquipment();
    renderAdminRequests();
    renderAdminMembers();
    renderReports();
  }

  function memberEquipmentResults() {
    const query = get("member-search").value.trim().toLowerCase();
    const category = get("member-category").value;
    const availability = get("member-availability").value;
    return data.equipment.filter((item) => `${item.name} ${item.category} ${item.description}`.toLowerCase().includes(query) && (!category || item.category === category) && (!availability || item.status === availability));
  }
  function openBorrowModal(equipmentId) {
    const available = data.equipment.filter((item) => item.available > 0 && item.status === "Available");
    if (!available.length) { showToast("Nothing is available to borrow right now.", true); return; }
    const select = get("borrow-equipment");
    select.innerHTML = available.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)} · ${item.available} available</option>`).join("");
    if (equipmentId && available.some((item) => item.id === equipmentId)) select.value = equipmentId;
    const selected = available.find((item) => item.id === select.value);
    get("borrow-quantity").max = String(selected?.available || 1);
    const minStart = offsetDate(0);
    get("borrow-start").min = minStart;
    get("borrow-start").value = offsetDate(1);
    get("borrow-due").min = offsetDate(1);
    get("borrow-due").value = offsetDate(4);
    get("borrow-form").reset();
    get("borrow-equipment").value = equipmentId && available.some((item) => item.id === equipmentId) ? equipmentId : available[0].id;
    get("borrow-quantity").max = String(available.find((item) => item.id === get("borrow-equipment").value)?.available || 1);
    get("borrow-quantity").value = "1";
    get("borrow-email").value = activeUser.email || "";
    get("borrow-start").value = offsetDate(1);
    get("borrow-due").value = offsetDate(4);
    bootstrap.Modal.getOrCreateInstance(get("borrow-modal")).show();
  }
  function renderMemberBrowse() {
    const categorySelect = get("member-category");
    const currentCategory = categorySelect.value;
    const categories = [...new Set(data.equipment.map((item) => item.category).filter(Boolean))].sort();
    categorySelect.innerHTML = `<option value="">All categories</option>${categories.map((category) => `<option value="${escapeHtml(category)}">${escapeHtml(category)}</option>`).join("")}`;
    if (categories.includes(currentCategory)) categorySelect.value = currentCategory;
    const results = memberEquipmentResults();
    const availableUnits = results.reduce((sum, item) => sum + Number(item.available || 0), 0);
    get("member-result-count").textContent = `${results.length} kinds · ${availableUnits} available`;
    get("member-equipment-grid").innerHTML = results.length ? results.map((item) => `<article class="equipment-card"><div class="equipment-image"><span class="equipment-category">${escapeHtml(item.category)}</span><span class="equipment-state status-pill ${statusClass(item.status)}">${escapeHtml(item.status)}</span>${item.photo ? `<img src="${escapeHtml(item.photo)}" alt="${escapeHtml(item.name)}">` : `<span class="equipment-emoji" aria-hidden="true">${escapeHtml(item.emoji || categoryIcons[item.category] || "📦")}</span>`}</div><div class="equipment-body"><div class="equipment-title-line"><h3>${escapeHtml(item.name)}</h3></div><p class="equipment-description">${escapeHtml(item.description || "Ready to be shared with the community.")}</p><div class="equipment-foot"><small>${Number(item.available)} of ${Number(item.quantity)} available · ${escapeHtml(item.condition)}</small>${item.available > 0 && item.status === "Available" ? `<button class="btn btn-success" type="button" data-action="borrow" data-id="${escapeHtml(item.id)}">Request</button>` : `<span class="text-secondary small">Not available</span>`}</div></div></article>`).join("") : `<div class="empty-state"><strong>No equipment found.</strong>Try another search or category.</div>`;
  }
  function renderMemberHome() {
    const requests = currentUserRequests();
    const active = requests.filter(isActiveBorrowing);
    const pending = requests.filter((request) => request.status === "Pending");
    const units = data.equipment.reduce((sum, item) => sum + Number(item.available || 0), 0);
    get("member-metrics").innerHTML = [["▣", "Things to share", data.equipment.length, "Types of equipment"], ["✳", "Ready to borrow", units, "Items waiting for a project"], ["↗", "My active borrowings", active.length, "Out in the community"], ["◷", "Requests in review", pending.length, "We’ll keep you posted"]].map(([icon, label, value, note]) => `<article class="metric-card"><span class="metric-icon">${icon}</span><span><span class="metric-label">${label}</span><strong class="metric-value">${value}</strong><small class="metric-foot">${note}</small></span></article>`).join("");
    const featured = data.equipment.filter((item) => item.available > 0 && item.status === "Available").slice(0, 4);
    get("featured-items").innerHTML = featured.length ? featured.map((item) => `<div class="featured-item"><span class="item-emoji">${escapeHtml(item.emoji || categoryIcons[item.category] || "📦")}</span><span><strong>${escapeHtml(item.name)}</strong><small>${item.available} available · ${escapeHtml(item.category)}</small></span><button class="btn btn-success" type="button" data-action="borrow" data-id="${escapeHtml(item.id)}">Borrow</button></div>`).join("") : `<div class="empty-state">Nothing available just now.</div>`;
    get("member-latest-requests").innerHTML = requests.length ? requests.slice().sort((a, b) => (b.createdAt || "").localeCompare(a.createdAt || "")).slice(0, 4).map((request) => `<div class="member-request-preview"><span class="item-emoji">${escapeHtml(itemFor(request)?.emoji || "📦")}</span><span class="member-request-copy"><strong>${escapeHtml(itemFor(request)?.name || "Equipment")}</strong><small>${formatDate(request.startDate)} – ${formatDate(request.dueDate)}</small></span><span class="status-pill ${statusClass(request.status)}">${escapeHtml(request.status)}</span></div>`).join("") : `<div class="empty-state"><strong>Your next project starts here.</strong>You haven’t requested anything yet.</div>`;
  }
  function renderMemberRequests() {
    const requests = currentUserRequests().filter((request) => !requestFilter || request.status === requestFilter).sort((a, b) => (b.createdAt || "").localeCompare(a.createdAt || ""));
    get("member-request-count").textContent = String(currentUserRequests().filter((request) => request.status === "Pending").length);
    get("member-requests-table").innerHTML = requests.length ? requests.map((request) => `<tr><td>${itemCell(itemFor(request))}</td><td>${formatDate(request.startDate)} – ${formatDate(request.dueDate)}<small class="d-block mt-1 text-secondary">Requested ${formatDate(request.createdAt)}</small></td><td>${request.quantity}</td><td><span class="status-pill ${statusClass(request.status)}">${escapeHtml(request.status)}</span></td><td>${escapeHtml(request.purpose)}</td></tr>`).join("") : `<tr><td colspan="5" class="empty-table">No requests here yet. Your next project could be the first.</td></tr>`;
  }
  function renderMember() {
    renderMemberHome();
    renderMemberBrowse();
    renderMemberRequests();
  }
  function renderAll() {
    if (!activeUser) return;
    if (isAdminPage) renderAdmin();
    else renderMember();
  }

  function openEquipmentForm(item) {
    if (activeUser.role !== "Administrator") { showToast("Only administrators can change equipment.", true); return; }
    const form = get("edit-form");
    form.reset();
    const values = item || { id: "", name: "", category: "Tools", quantity: 1, available: 1, emoji: "📦", condition: "Good", status: "Available", description: "", photo: "" };
    Object.entries({ id: values.id, name: values.name, category: values.category, quantity: values.quantity, available: values.available, emoji: values.emoji || "📦", condition: values.condition, status: values.status, description: values.description, photo: values.photo || "" }).forEach(([name, value]) => { form.elements[name].value = value ?? ""; });
    get("edit-modal-title").textContent = item ? "Edit equipment" : "Add equipment";
    bootstrap.Modal.getOrCreateInstance(get("edit-modal")).show();
  }
  function confirmAction(title, message, action, confirmLabel = "Remove") {
    confirmCallback = action;
    get("confirm-title").textContent = title;
    get("confirm-copy").textContent = message;
    get("confirm-remove").textContent = confirmLabel;
    bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).show();
  }
  function createRequest(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    const item = data.equipment.find((entry) => entry.id === values.get("equipmentId"));
    const quantity = Number(values.get("quantity"));
    const startDate = String(values.get("startDate"));
    const dueDate = String(values.get("dueDate"));
    if (!item || quantity < 1 || quantity > item.available || startDate < offsetDate(0) || dueDate < startDate) {
      showToast("Check availability, quantity, and dates before sending.", true);
      return;
    }
    const success = commit((next) => {
      next.requests.unshift({ id: makeId(), equipmentId: item.id, borrower: activeUser.name, email: String(values.get("email")).trim(), quantity, startDate, dueDate, purpose: String(values.get("purpose")).trim(), status: "Pending", conditionOut: item.condition, createdAt: offsetDate(0) });
      addActivity(next, `${activeUser.name} requested ${item.name}`, "↗");
    }, "Request sent! We’ll let you know when it’s reviewed.");
    if (success) {
      bootstrap.Modal.getOrCreateInstance(get("borrow-modal")).hide();
      form.reset();
      updateProfile();
    }
  }
  function reviewRequest(id, decision) {
    if (activeUser.role !== "Administrator" && activeUser.role !== "Organization officer" && activeUser.role !== "Custodian") { showToast("You don’t have permission to review borrowing requests.", true); return; }
    const request = data.requests.find((item) => item.id === id);
    if (!request || request.status !== "Pending") return;
    const item = itemFor(request);
    if (decision === "Approved" && (!item || item.available < request.quantity)) { showToast("There isn’t enough equipment available to approve this request.", true); return; }
    commit((next) => {
      const target = next.requests.find((entry) => entry.id === id);
      const gear = next.equipment.find((entry) => entry.id === target.equipmentId);
      target.status = decision;
      if (decision === "Approved" && gear) {
        gear.available -= target.quantity;
        if (gear.available === 0) gear.status = "Borrowed";
        target.conditionOut = gear.condition;
      }
      addActivity(next, `${activeUser.name} ${decision.toLowerCase()} ${gear?.name || "a borrowing request"}`, decision === "Approved" ? "✓" : "×");
    }, decision === "Approved" ? "Request approved." : "Request declined.");
  }
  function openReturnModal(id) {
    if (activeUser.role !== "Administrator" && activeUser.role !== "Organization officer" && activeUser.role !== "Custodian") { showToast("Only community stewards can record returns.", true); return; }
    const request = data.requests.find((item) => item.id === id);
    if (!request || !isActiveBorrowing(request)) return;
    get("return-form").reset();
    get("return-form").elements.requestId.value = request.id;
    get("return-copy").textContent = `Checking in ${itemFor(request)?.name || "equipment"} from ${request.borrower}. Please note its condition.`;
    bootstrap.Modal.getOrCreateInstance(get("return-modal")).show();
  }
  function recordReturn(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    const id = String(values.get("requestId"));
    const normalized = String(values.get("condition"));
    const request = data.requests.find((item) => item.id === id);
    if (!request || !isActiveBorrowing(request)) return;
    const saved = commit((next) => {
      const target = next.requests.find((entry) => entry.id === id);
      const gear = next.equipment.find((entry) => entry.id === target.equipmentId);
      target.status = "Returned";
      target.returnedAt = offsetDate(0);
      target.returnCondition = normalized;
      target.returnNotes = String(values.get("notes")).trim();
      if (gear) {
        if (normalized === "Missing") gear.quantity = Math.max(0, gear.quantity - target.quantity);
        else gear.available = Math.min(gear.quantity, gear.available + target.quantity);
        if (["Damaged", "Needs repair"].includes(normalized)) {
          gear.condition = normalized;
          gear.status = "Damaged";
        } else if (normalized === "Incomplete") {
          gear.condition = "Fair";
          gear.status = gear.available ? "Available" : "Borrowed";
        } else if (gear.available > 0) gear.status = "Available";
      }
      addActivity(next, `${target.borrower} returned ${gear?.name || "equipment"} · ${normalized.toLowerCase()}`, normalized === "Good" ? "↩" : "!");
    }, "Return recorded and condition noted.");
    if (saved) bootstrap.Modal.getOrCreateInstance(get("return-modal")).hide();
  }
  function removeEquipment(id) {
    if (activeUser.role !== "Administrator") { showToast("Only administrators can remove equipment.", true); return; }
    const item = data.equipment.find((entry) => entry.id === id);
    if (!item) return;
    if (data.requests.some((request) => request.equipmentId === id && isActiveBorrowing(request))) { showToast("This equipment is currently borrowed and cannot be removed.", true); return; }
    confirmAction("Remove equipment?", `Remove ${item.name} from the collection? Its borrowing history will remain in reports.`, () => {
      const saved = commit((next) => {
        next.equipment = next.equipment.filter((entry) => entry.id !== id);
        addActivity(next, `Removed ${item.name} from the collection`);
      }, "Equipment removed from the collection.");
      if (saved) bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).hide();
    });
  }
  function exportCsv(requests, filename, headers) {
    const fields = requests.map((request) => [itemFor(request)?.name || "Equipment removed", request.borrower, request.email, request.quantity, request.startDate, request.dueDate, request.status, request.purpose, request.returnCondition || ""]);
    const csv = [headers, ...fields].map((row) => row.map((field) => `"${String(field ?? "").replaceAll('"', '""')}"`).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
    showToast("Your report is ready to download.");
  }
  function setMemberAccountModal() {
    const select = get("member-account-select");
    select.innerHTML = data.people.map((person) => `<option value="${escapeHtml(person.email)}"${person.email.toLowerCase() === activeUser.email.toLowerCase() ? " selected" : ""}>${escapeHtml(person.name)} · ${escapeHtml(person.role)}</option>`).join("");
    get("register-form").classList.add("d-none");
    get("show-register").classList.remove("d-none");
    bootstrap.Modal.getOrCreateInstance(get("account-modal")).show();
  }

  function initAdmin() {
    const steward = data.people.find((person) => person.email.toLowerCase() === activeUser.email.toLowerCase() && canAccessStewardDashboard(person));
    get("today").textContent = new Intl.DateTimeFormat("en", { weekday: "short", month: "short", day: "numeric" }).format(today);
    if (!steward) {
      get("admin-app").classList.add("d-none");
      get("access-guard").classList.remove("d-none");
      const stewards = data.people.filter(canAccessStewardDashboard);
      get("admin-account").innerHTML = stewards.map((person) => `<option value="${escapeHtml(person.email)}">${escapeHtml(person.name)} · ${escapeHtml(person.role)}</option>`).join("") || `<option value="">No authorized accounts found</option>`;
      get("switch-admin").disabled = stewards.length === 0;
      get("switch-admin").addEventListener("click", () => {
        const selected = data.people.find((person) => person.email === get("admin-account").value && canAccessStewardDashboard(person));
        if (!selected) return;
        setSession(selected);
        get("admin-app").classList.remove("d-none");
        get("access-guard").classList.add("d-none");
        renderAll();
        showSection(location.hash.slice(1) || "overview", false);
      });
      return;
    }
    get("admin-app").classList.remove("d-none");
    get("access-guard").classList.add("d-none");
    updateProfile();
    renderAll();
    showSection(location.hash.slice(1) || "overview", false);
  }
  function initMember() {
    const member = data.people.find((person) => person.email.toLowerCase() === activeUser.email.toLowerCase() && person.role === "Member");
    if (!member) {
      const firstMember = data.people.find((person) => person.role === "Member");
      if (firstMember) {
        setSession(firstMember);
      }
    }
    get("today").textContent = new Intl.DateTimeFormat("en", { weekday: "short", month: "short", day: "numeric" }).format(today);
    updateProfile();
    renderAll();
    showSection(location.hash.slice(1) || "home", false);
  }

  function initEvents() {
    document.querySelectorAll(".side-link").forEach((link) => link.addEventListener("click", (event) => {
      event.preventDefault();
      showSection(link.dataset.section);
    }));
    document.querySelectorAll("[data-navigate]").forEach((button) => button.addEventListener("click", () => showSection(button.dataset.navigate)));
    window.addEventListener("popstate", () => showSection(location.hash.slice(1), false));
    get("menu-toggle").addEventListener("click", () => get("sidebar").classList.toggle("open"));
    document.addEventListener("click", (event) => {
      const action = event.target.closest("[data-action]");
      if (!action) return;
      const { id } = action.dataset;
      if (action.dataset.action === "add-equipment") openEquipmentForm();
      if (action.dataset.action === "edit-equipment") openEquipmentForm(data.equipment.find((item) => item.id === id));
      if (action.dataset.action === "delete-equipment") removeEquipment(id);
      if (action.dataset.action === "approve") reviewRequest(id, "Approved");
      if (action.dataset.action === "reject") reviewRequest(id, "Rejected");
      if (action.dataset.action === "checkin") openReturnModal(id);
      if (action.dataset.action === "borrow") openBorrowModal(id);
      if (action.dataset.action === "add-member") {
        if (activeUser.role !== "Administrator") { showToast("Only administrators can add community profiles.", true); return; }
        get("member-form").reset();
        bootstrap.Modal.getOrCreateInstance(get("member-modal")).show();
      }
      if (action.dataset.action === "remove-person") {
        if (!isAdministrator()) { showToast("Only administrators can remove community profiles.", true); return; }
        const person = data.people.find((entry) => entry.id === id);
        if (!person || person.email.toLowerCase() === activeUser.email.toLowerCase()) return;
        confirmAction("Remove this person?", `Remove ${person.name} from the community? Borrowing history will remain in reports.`, () => {
          const saved = commit((next) => {
            next.people = next.people.filter((entry) => entry.id !== id);
            addActivity(next, `${person.name} left the community`);
          }, "Person removed from the community.");
          if (saved) bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).hide();
        });
      }
    });

    if (isAdminPage) {
      get("top-profile").addEventListener("click", () => {
        const stewards = data.people.filter(canAccessStewardDashboard);
        get("admin-switch-account").innerHTML = stewards.map((person) => `<option value="${escapeHtml(person.email)}"${person.email.toLowerCase() === activeUser.email.toLowerCase() ? " selected" : ""}>${escapeHtml(person.name)} · ${escapeHtml(person.role)}</option>`).join("");
        bootstrap.Modal.getOrCreateInstance(get("account-modal")).show();
      });
      get("confirm-admin-switch").addEventListener("click", () => {
        const person = data.people.find((entry) => entry.email === get("admin-switch-account").value && canAccessStewardDashboard(entry));
        if (!person) { showToast("Choose an authorized community steward account to continue.", true); return; }
        setSession(person);
        renderAll();
        showSection(currentSection, false);
        bootstrap.Modal.getOrCreateInstance(get("account-modal")).hide();
        showToast(`Viewing GamitShare as ${person.name}.`);
      });
      get("equipment-search").addEventListener("input", renderAdminEquipment);
      get("equipment-category").addEventListener("change", renderAdminEquipment);
      get("equipment-status").addEventListener("change", renderAdminEquipment);
      get("request-search").addEventListener("input", renderAdminRequests);
      get("request-status").addEventListener("change", renderAdminRequests);
      get("report-period").addEventListener("change", renderReports);
      get("edit-quantity").addEventListener("input", () => { get("edit-available").max = get("edit-quantity").value; });
      get("edit-form").addEventListener("submit", (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        if (activeUser.role !== "Administrator") { showToast("Only administrators can change equipment.", true); return; }
        const values = new FormData(form);
        const quantity = Number(values.get("quantity"));
        const available = Number(values.get("available"));
        const id = String(values.get("id"));
        if (quantity < 0 || available < 0 || available > quantity) { showToast("Available quantity must be between zero and the total.", true); return; }
        const original = data.equipment.find((item) => item.id === id);
        const name = String(values.get("name")).trim();
        const saved = commit((next) => {
          const fields = { name, category: String(values.get("category")), quantity, available, emoji: String(values.get("emoji")).trim() || "📦", condition: String(values.get("condition")), status: String(values.get("status")), description: String(values.get("description")).trim(), photo: String(values.get("photo")).trim() };
          if (id) Object.assign(next.equipment.find((item) => item.id === id), fields);
          else next.equipment.unshift({ id: makeId(), ...fields });
          addActivity(next, `${id ? "Updated" : "Added"} ${name} ${id ? "equipment details" : "to the collection"}`);
        }, original ? "Equipment details saved." : "Added to the community collection.");
        if (saved) bootstrap.Modal.getOrCreateInstance(get("edit-modal")).hide();
      });
      get("member-form").addEventListener("submit", (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const values = new FormData(form);
        const email = String(values.get("email")).trim().toLowerCase();
        if (data.people.some((person) => person.email.toLowerCase() === email)) { showToast("A person with this email is already in the community.", true); return; }
        const name = String(values.get("name")).trim();
        const role = String(values.get("role"));
        const saved = commit((next) => {
          next.people.push({ id: makeId(), name, email, role, joined: offsetDate(0) });
          addActivity(next, `${name} joined as a ${role.toLowerCase()}`);
        }, `${name} has been added to the community.`);
        if (saved) bootstrap.Modal.getOrCreateInstance(get("member-modal")).hide();
      });
      get("return-form").addEventListener("submit", recordReturn);
      get("admin-members-table").addEventListener("change", (event) => {
        const select = event.target.closest('[data-action="change-role"]');
        if (!select || activeUser.role !== "Administrator") return;
        const person = data.people.find((entry) => entry.id === select.dataset.id);
        if (!person || (person.email.toLowerCase() === activeUser.email.toLowerCase() && select.value !== "Administrator")) {
          showToast("Keep at least one administrator on your own account.", true);
          renderAdminMembers();
          return;
        }
        const name = person.name;
        const saved = commit((next) => {
          next.people.find((entry) => entry.id === select.dataset.id).role = select.value;
          addActivity(next, `${name} is now a ${select.value.toLowerCase()}`);
        }, `${name}’s role was updated.`);
        if (saved && person.email.toLowerCase() === activeUser.email.toLowerCase()) {
          activeUser = data.people.find((entry) => entry.email.toLowerCase() === activeUser.email.toLowerCase());
          setSession(activeUser);
        }
      });
      get("export-requests").addEventListener("click", () => exportCsv(data.requests, `gamitshare-borrowing-${offsetDate(0)}.csv`, ["Equipment", "Borrower", "Email", "Quantity", "Start date", "Return date", "Status", "Purpose", "Return condition"]));
      get("export-report").addEventListener("click", () => exportCsv(reportRequests(), `gamitshare-report-${offsetDate(0)}.csv`, ["Equipment", "Borrower", "Email", "Quantity", "Start date", "Return date", "Status", "Purpose", "Return condition"]));
      get("confirm-remove").addEventListener("click", () => { if (confirmCallback) confirmCallback(); });
      get("confirm-modal").addEventListener("hidden.bs.modal", () => { confirmCallback = null; });
    } else {
      get("member-search").addEventListener("input", renderMemberBrowse);
      get("member-category").addEventListener("change", renderMemberBrowse);
      get("member-availability").addEventListener("change", renderMemberBrowse);
      get("borrow-equipment").addEventListener("change", () => {
        const item = data.equipment.find((entry) => entry.id === get("borrow-equipment").value);
        get("borrow-quantity").max = String(item?.available || 1);
        if (Number(get("borrow-quantity").value) > Number(get("borrow-quantity").max)) get("borrow-quantity").value = get("borrow-quantity").max;
      });
      get("borrow-form").addEventListener("submit", createRequest);
      document.querySelectorAll("[data-member-filter]").forEach((button) => button.addEventListener("click", () => {
        requestFilter = button.dataset.memberFilter;
        document.querySelectorAll("[data-member-filter]").forEach((tab) => tab.classList.toggle("active", tab === button));
        renderMemberRequests();
      }));
      const openAccount = () => setMemberAccountModal();
      get("member-account-button").addEventListener("click", openAccount);
      get("member-account-top").addEventListener("click", openAccount);
      get("switch-member").addEventListener("click", () => {
        const person = data.people.find((entry) => entry.email === get("member-account-select").value);
        if (!person) return;
        setSession(person);
        bootstrap.Modal.getOrCreateInstance(get("account-modal")).hide();
        renderAll();
        showToast(`Viewing GamitShare as ${person.name}.`);
      });
      get("show-register").addEventListener("click", () => {
        get("register-form").classList.remove("d-none");
        get("show-register").classList.add("d-none");
      });
      get("register-form").addEventListener("submit", (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const values = new FormData(form);
        const email = String(values.get("email")).trim().toLowerCase();
        if (data.people.some((person) => person.email.toLowerCase() === email)) { showToast("An account with this email already exists.", true); return; }
        const person = { id: makeId(), name: String(values.get("name")).trim(), email, role: "Member", joined: offsetDate(0) };
        const saved = commit((next) => {
          next.people.push(person);
          addActivity(next, `${person.name} joined the community`);
        }, `Welcome to the community, ${person.name}!`);
        if (saved) {
          setSession(data.people.find((entry) => entry.email === person.email));
          bootstrap.Modal.getOrCreateInstance(get("account-modal")).hide();
          form.reset();
          get("register-form").classList.add("d-none");
          get("show-register").classList.remove("d-none");
        }
      });
    }
    window.addEventListener("storage", (event) => {
      if (event.key === DATA_KEY || event.key === SESSION_KEY) {
        data = readData();
        const person = readSession();
        if (person) activeUser = person;
        if (isAdminPage && !canAccessStewardDashboard(activeUser)) { location.reload(); return; }
        updateProfile();
        renderAll();
        if (isAdminPage) showSection(currentSection, false);
      }
    });
  }

  function init() {
    data = readData();
    if (!data.people.length) data.people = defaultData().people;
    activeUser = readSession();
    if (!activeUser) {
      activeUser = isAdminPage ? data.people.find((person) => person.role === "Administrator") : data.people.find((person) => person.role === "Member");
      if (!activeUser) activeUser = data.people[0];
      if (activeUser) {
        try { localStorage.setItem(SESSION_KEY, JSON.stringify({ name: activeUser.name, email: activeUser.email, role: activeUser.role })); } catch (error) { console.error("Could not initialize the demo account.", error); }
      }
    }
    if (!activeUser) {
      showToast("No community accounts are available.", true);
      return;
    }
    initEvents();
    if (isAdminPage) initAdmin();
    else initMember();
  }

  init();
})();
