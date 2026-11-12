(() => {
  "use strict";

  const isAdminPage = document.body.dataset.dashboard === "admin";
  const API_BASE = "../../controller";
  const DATA_KEY = "gamitshare-data-v1";
  const ACCOUNTS_KEY = "gamitshare-local-accounts-v1";
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
      { id: "1", name: "Cordless drill set", category: "Tools", emoji: "🛠️", description: "For those little fixes and big DIY dreams.", quantity: 3, available: 2, condition: "Good", status: "Available", photo: "" },
      { id: "2", name: "Folding event tent", category: "Events", emoji: "⛺", description: "A little shade for the next neighborhood gathering.", quantity: 2, available: 0, condition: "Good", status: "Borrowed", photo: "" },
      { id: "3", name: "Portable speaker", category: "Electronics", emoji: "🔊", description: "Bring people together, one playlist at a time.", quantity: 4, available: 3, condition: "Good", status: "Available", photo: "" },
      { id: "4", name: "Community garden kit", category: "Garden", emoji: "🪴", description: "Gloves, hand trowels, and room to grow.", quantity: 8, available: 6, condition: "Good", status: "Available", photo: "" },
      { id: "5", name: "Projector & screen", category: "Electronics", emoji: "📽️", description: "Movie nights, workshops, and bright ideas.", quantity: 1, available: 0, condition: "Fair", status: "Under maintenance", photo: "" },
      { id: "6", name: "Folding tables", category: "Events", emoji: "🪑", description: "Sturdy tables ready for a crowd.", quantity: 6, available: 4, condition: "Good", status: "Available", photo: "" }
    ],
    requests: [
      { id: "1", equipmentId: "2", borrower: "Lina Reyes", email: "lina.reyes@example.org", quantity: 1, startDate: offsetDate(-2), dueDate: offsetDate(3), purpose: "Weekend community market", status: "Approved", conditionOut: "Good", createdAt: offsetDate(-5) },
      { id: "2", equipmentId: "1", borrower: "Noah Cruz", email: "noah.cruz@example.org", quantity: 1, startDate: offsetDate(1), dueDate: offsetDate(4), purpose: "Repairing the reading nook shelves", status: "Pending", conditionOut: "Good", createdAt: offsetDate(-1) },
      { id: "3", equipmentId: "3", borrower: "Maya Santos", email: "maya.santos@example.org", quantity: 1, startDate: offsetDate(-12), dueDate: offsetDate(-5), purpose: "Youth group film afternoon", status: "Overdue", conditionOut: "Good", createdAt: offsetDate(-15) },
      { id: "4", equipmentId: "4", borrower: "Rafi Dela Cruz", email: "rafi.dc@example.org", quantity: 2, startDate: offsetDate(-1), dueDate: offsetDate(2), purpose: "Saturday garden workday", status: "Approved", conditionOut: "Good", createdAt: offsetDate(-4) },
      { id: "5", equipmentId: "6", borrower: "Lina Reyes", email: "lina.reyes@example.org", quantity: 2, startDate: offsetDate(-21), dueDate: offsetDate(-17), purpose: "Community potluck", status: "Returned", conditionOut: "Good", returnCondition: "Good", createdAt: offsetDate(-25), returnedAt: offsetDate(-17) },
      { id: "6", equipmentId: "1", borrower: "Ivo Mercado", email: "ivo.m@example.org", quantity: 1, startDate: offsetDate(5), dueDate: offsetDate(8), purpose: "Fixing a broken fence", status: "Pending", conditionOut: "Good", createdAt: offsetDate(-1) }
    ],
    people: [
      { id: "1", name: "Maya Santos", email: "maya.santos@example.org", role: "Administrator", joined: "2025-03-12" },
      { id: "4", name: "Lina Reyes", email: "lina.reyes@example.org", role: "Member", joined: "2025-07-08" },
      { id: "2", name: "Noah Cruz", email: "noah.cruz@example.org", role: "Organization officer", joined: "2025-04-20" },
      { id: "3", name: "Rafi Dela Cruz", email: "rafi.dc@example.org", role: "Custodian", joined: "2025-05-02" },
      { id: "5", name: "Ivo Mercado", email: "ivo.m@example.org", role: "Member", joined: "2025-08-14" }
    ],
    activity: []
  });

  let data;
  let activeUser;
  let currentSection = isAdminPage ? "overview" : "home";
  let requestFilter = "";
  let confirmCallback = null;
  let requestSubmissionInProgress = false;
  const pendingRequestReviews = new Set();
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
  const itemFor = (request) => data.equipment.find((item) => String(item.id) === String(request.equipmentId));
  const isActiveBorrowing = (request) => ["Approved", "Overdue"].includes(request.status);
  // Resolve legacy relative image paths; new equipment photos use external URLs.
  const photoSrc = (photo = "") => (!photo || /^(https?:|data:|\/)/i.test(photo)) ? photo : `${API_BASE}/../${photo}`;
  const statusClass = (status) => `status-${String(status || "").toLowerCase().replaceAll(" ", "-")}`;

  // ── BACKEND API INTEGRATION ─────────────────────────────────────────────────
  async function apiCall(endpoint, options = {}) {
    const headers = {
      "Content-Type": "application/json",
      ...(options.headers || {})
    };
    try {
      const res = await fetch(`${API_BASE}/${endpoint}`, {
        credentials: "same-origin",
        ...options,
        headers
      });
      if (!res.ok && res.status === 401 && !isAdminPage) {
        // If unauthenticated on protected member call
      }
      return await res.json();
    } catch (err) {
      console.warn(`[GamitShare API] Error calling ${endpoint}:`, err);
      return null;
    }
  }

  async function syncWithServer() {
    try {
      const [eqRes, reqRes, actRes] = await Promise.all([
        apiCall("equipment.php"),
        apiCall(isAdminPage ? "requests.php" : `requests.php?email=${encodeURIComponent(activeUser?.email || "")}`),
        apiCall("activity.php?limit=12")
      ]);

      if (eqRes?.success && Array.isArray(eqRes.equipment)) {
        data.equipment = eqRes.equipment.map((item) => ({
          id: String(item.id),
          name: item.name,
          category: item.category,
          category_id: item.category_id,
          emoji: item.emoji || "📦",
          description: item.description || "",
          quantity: Number(item.quantity),
          available: Number(item.available),
          condition: item.condition || "Good",
          status: item.status || "Available",
          photo: item.photo || ""
        }));
      }

      if (reqRes?.success && Array.isArray(reqRes.requests)) {
        data.requests = reqRes.requests.map((r) => ({
          id: String(r.id),
          equipmentId: String(r.equipmentId),
          borrower: r.borrower,
          email: r.email,
          quantity: Number(r.quantity),
          startDate: r.startDate,
          dueDate: r.dueDate,
          purpose: r.purpose,
          status: r.status,
          conditionOut: r.conditionOut,
          returnCondition: r.returnCondition,
          returnNotes: r.returnNotes,
          returnedAt: r.returnedAt,
          createdAt: r.createdAt
        }));
      }

      if (actRes?.success && Array.isArray(actRes.activity)) {
        data.activity = actRes.activity.map((a) => ({
          id: String(a.id),
          icon: a.icon || "✳",
          text: a.text,
          date: a.date
        }));
      }

      if (isAdminPage) {
        const memRes = await apiCall("members.php");
        if (memRes?.success && Array.isArray(memRes.members)) {
          data.people = memRes.members.map((m) => ({
            id: String(m.id),
            name: m.name,
            email: m.email,
            role: m.role,
            joined: m.joined
          }));
        }
      }

      persist(data);
      renderAll();
    } catch (e) {
      console.warn("[GamitShare] Server sync error, using local state:", e);
    }
  }

  function readData() {
    try {
      const saved = localStorage.getItem(DATA_KEY);
      if (!saved) return defaultData();
      const parsed = JSON.parse(saved);
      if (!Array.isArray(parsed.equipment) || !Array.isArray(parsed.requests) || !Array.isArray(parsed.people)) {
        throw new Error("Saved GamitShare data is incomplete.");
      }
      if (!Array.isArray(parsed.activity)) parsed.activity = [];
      delete parsed.suggestions;
      return parsed;
    } catch (error) {
      console.error("Could not read GamitShare data.", error);
      return defaultData();
    }
  }

  function readSession() {
    try {
      // 1. Check PHP session user stored at login
      const phpUser = JSON.parse(sessionStorage.getItem("gamitshare-php-user") || "null");
      if (phpUser && phpUser.email) {
        return phpUser;
      }
      // 2. Check localStorage session
      const stored = JSON.parse(localStorage.getItem(SESSION_KEY) || "null");
      if (stored && stored.email) {
        const matched = data.people.find((person) => person.email?.toLowerCase() === stored.email?.toLowerCase());
        return matched || stored;
      }
      return null;
    } catch (error) {
      console.error("Could not read the active GamitShare account.", error);
      return null;
    }
  }

  function mergeLocalAccounts() {
    try {
      const accounts = JSON.parse(localStorage.getItem(ACCOUNTS_KEY) || "[]");
      if (!Array.isArray(accounts)) return;
      accounts.forEach((account) => {
        if (!account?.email || !account?.name || data.people.some((person) => person.email?.toLowerCase() === account.email.toLowerCase())) return;
        data.people.push({ id: makeId(), name: account.name, email: account.email, role: "Member", joined: account.joined || offsetDate(0) });
      });
    } catch (error) {
      console.error("Could not load local GamitShare accounts.", error);
    }
  }

  function persist(nextData = data) {
    try {
      localStorage.setItem(DATA_KEY, JSON.stringify(nextData));
      data = nextData;
      return true;
    } catch (error) {
      console.error("Could not save GamitShare data.", error);
      return false;
    }
  }

  function setSession(person) {
    activeUser = person;
    try {
      sessionStorage.setItem("gamitshare-php-user", JSON.stringify({ name: person.name, email: person.email, role: person.role }));
      localStorage.setItem(SESSION_KEY, JSON.stringify({ name: person.name, email: person.email, role: person.role }));
    } catch (error) {
      console.error("Could not save the selected account.", error);
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
      if (get("borrow-email")) get("borrow-email").value = activeUser.email || "";
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

  function itemCell(item) {
    return `<span class="item-cell"><span class="item-emoji">${escapeHtml(item?.emoji || "📦")}</span><span><strong>${escapeHtml(item?.name || "Equipment removed")}</strong><small>${escapeHtml(item?.category || "")}</small></span></span>`;
  }

  function showSection(section, writeHash = true) {
    const availableSections = isAdminPage
      ? ["overview", "equipment", "requests", "reports", ...(isAdministrator() ? ["members"] : [])]
      : ["home", "browse", "my-requests"];
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
    get("admin-equipment-grid").innerHTML = results.length ? results.map((item) => `<article class="equipment-card"><div class="equipment-image"><span class="equipment-category">${escapeHtml(item.category)}</span><span class="equipment-state status-pill ${statusClass(item.status)}">${escapeHtml(item.status)}</span>${item.photo ? `<img src="${escapeHtml(photoSrc(item.photo))}" alt="${escapeHtml(item.name)}" loading="lazy" onerror="this.remove()">` : `<span class="equipment-emoji" aria-hidden="true">${escapeHtml(item.emoji || categoryIcons[item.category] || "📦")}</span>`}</div><div class="equipment-body"><div class="equipment-title-line"><h3>${escapeHtml(item.name)}</h3></div><p class="equipment-description">${escapeHtml(item.description || "Ready to be shared with the community.")}</p><div class="equipment-foot"><small>${Number(item.available)} of ${Number(item.quantity)} available · ${escapeHtml(item.condition)}</small>${isAdministrator() ? `<span class="d-flex gap-1"><button class="btn btn-outline-secondary" type="button" data-action="edit-equipment" data-id="${escapeHtml(item.id)}" aria-label="Edit ${escapeHtml(item.name)}">Edit</button><button class="table-action danger" type="button" data-action="delete-equipment" data-id="${escapeHtml(item.id)}" aria-label="Remove ${escapeHtml(item.name)}">×</button></span>` : ""}</div></div></article>`).join("") : `<div class="empty-state"><strong>No equipment found.</strong>Try changing a filter or add something to the collection.</div>`;
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
    get("admin-members-table").innerHTML = data.people.map((person) => `<tr><td><span class="table-person">${escapeHtml(person.name)}</span></td><td>${escapeHtml(person.email)}</td><td><select class="form-select role-select" data-action="change-role" data-id="${escapeHtml(person.id)}" aria-label="Role for ${escapeHtml(person.name)}">${roles.map((role) => `<option${role === person.role ? " selected" : ""}>${escapeHtml(role)}</option>`).join("")}</select></td><td>${formatDate(person.joined, { year: "numeric", month: "short", day: "numeric" })}</td><td class="text-end">${person.email?.toLowerCase() === activeUser.email?.toLowerCase() ? `<span class="text-secondary small">You</span>` : `<button class="table-action danger" type="button" data-action="remove-person" data-id="${escapeHtml(person.id)}" aria-label="Remove ${escapeHtml(person.name)}">Remove</button>`}</td></tr>`).join("");
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
    const usage = data.equipment.map((item) => ({ name: item.name, count: requests.filter((request) => String(request.equipmentId) === String(item.id)).length })).sort((a, b) => b.count - a.count).slice(0, 5);
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
    if (equipmentId && available.some((item) => String(item.id) === String(equipmentId))) select.value = String(equipmentId);
    const selected = available.find((item) => String(item.id) === String(select.value));
    get("borrow-quantity").max = String(selected?.available || 1);
    const minStart = offsetDate(0);
    get("borrow-start").min = minStart;
    get("borrow-start").value = offsetDate(1);
    get("borrow-due").min = offsetDate(1);
    get("borrow-due").value = offsetDate(4);
    get("borrow-form").reset();
    get("borrow-equipment").value = equipmentId && available.some((item) => String(item.id) === String(equipmentId)) ? String(equipmentId) : available[0].id;
    get("borrow-quantity").max = String(available.find((item) => String(item.id) === String(get("borrow-equipment").value))?.available || 1);
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
    get("member-equipment-grid").innerHTML = results.length ? results.map((item) => `<article class="equipment-card"><div class="equipment-image"><span class="equipment-category">${escapeHtml(item.category)}</span><span class="equipment-state status-pill ${statusClass(item.status)}">${escapeHtml(item.status)}</span>${item.photo ? `<img src="${escapeHtml(photoSrc(item.photo))}" alt="${escapeHtml(item.name)}" loading="lazy" onerror="this.remove()">` : `<span class="equipment-emoji" aria-hidden="true">${escapeHtml(item.emoji || categoryIcons[item.category] || "📦")}</span>`}</div><div class="equipment-body"><div class="equipment-title-line"><h3>${escapeHtml(item.name)}</h3></div><p class="equipment-description">${escapeHtml(item.description || "Ready to be shared with the community.")}</p><div class="equipment-foot"><small>${Number(item.available)} of ${Number(item.quantity)} available · ${escapeHtml(item.condition)}</small>${item.available > 0 && item.status === "Available" ? `<button class="btn btn-success" type="button" data-action="borrow" data-id="${escapeHtml(item.id)}">Request</button>` : `<span class="text-secondary small">Not available</span>`}</div></div></article>`).join("") : `<div class="empty-state"><strong>No equipment found.</strong>Try another search or category.</div>`;
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

  function showPhotoPreview(src) {
    const box = get("photo-preview");
    if (!box) return;
    box.innerHTML = src ? `<img src="${escapeHtml(src)}" alt="Equipment photo preview">` : `<span class="photo-preview-empty">No photo selected</span>`;
  }

  function openEquipmentForm(item) {
    if (activeUser.role !== "Administrator") { showToast("Only administrators can change equipment.", true); return; }
    const form = get("edit-form");
    form.reset();
    const values = item || { id: "", name: "", category: "Tools", quantity: 1, available: 1, emoji: "📦", condition: "Good", status: "Available", description: "", photo: "" };
    const photoUrl = /^https?:\/\//i.test(values.photo || "") ? values.photo : "";
    Object.entries({ id: values.id, name: values.name, category: values.category, quantity: values.quantity, available: values.available, emoji: values.emoji || "📦", condition: values.condition, status: values.status, description: values.description, photo: photoUrl }).forEach(([name, value]) => { if (form.elements[name]) form.elements[name].value = value ?? ""; });
    get("edit-modal-title").textContent = item ? "Edit equipment" : "Add equipment";
    showPhotoPreview(photoUrl);
    bootstrap.Modal.getOrCreateInstance(get("edit-modal")).show();
  }

  function confirmAction(title, message, action, confirmLabel = "Remove") {
    confirmCallback = action;
    get("confirm-title").textContent = title;
    get("confirm-copy").textContent = message;
    get("confirm-remove").textContent = confirmLabel;
    bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).show();
  }

  // ── BORROW REQUEST SUBMISSION ───────────────────────────────────────────────
  async function createRequest(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (requestSubmissionInProgress) return;
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    const item = data.equipment.find((entry) => String(entry.id) === String(values.get("equipmentId")));
    const quantity = Number(values.get("quantity"));
    const startDate = String(values.get("startDate"));
    const dueDate = String(values.get("dueDate"));
    if (!item || quantity < 1 || quantity > item.available || startDate < offsetDate(0) || dueDate < startDate) {
      showToast("Check availability, quantity, and dates before sending.", true);
      return;
    }

    const payload = {
      action: "create",
      equipmentId: item.id,
      quantity,
      startDate,
      dueDate,
      purpose: String(values.get("purpose")).trim(),
      email: String(values.get("email")).trim() || activeUser.email
    };

    const submitButton = form.querySelector('button[type="submit"]');
    const submitLabel = submitButton?.textContent;
    requestSubmissionInProgress = true;
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Sending…";
    }

    try {
      const res = await apiCall("requests.php?action=create", {
        method: "POST",
        body: JSON.stringify(payload)
      });

      if (res?.success) {
        data.requests.unshift({
          id: String(res.id),
          equipmentId: String(item.id),
          borrower: activeUser.name,
          email: activeUser.email,
          quantity,
          startDate,
          dueDate,
          purpose: payload.purpose,
          status: "Pending",
          conditionOut: item.condition,
          createdAt: offsetDate(0)
        });
        persist(data);
        renderAll();
        bootstrap.Modal.getOrCreateInstance(get("borrow-modal")).hide();
        form.reset();
        showToast(res.message || "Request sent! We’ll let you know when it’s reviewed.");
      } else {
        showToast(res?.message || "Check availability, quantity, and dates before sending.", true);
      }
    } finally {
      requestSubmissionInProgress = false;
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = submitLabel;
      }
    }
  }

  // ── STEWARD REVIEW REQUEST (APPROVE / REJECT) ──────────────────────────────
  async function reviewRequest(id, decision) {
    if (!canAccessStewardDashboard(activeUser)) {
      showToast("You don’t have permission to review borrowing requests.", true);
      return;
    }
    const key = String(id);
    const request = data.requests.find((item) => String(item.id) === key);
    if (!request || request.status !== "Pending" || pendingRequestReviews.has(key)) return;
    pendingRequestReviews.add(key);
    document.querySelectorAll('[data-action="approve"], [data-action="reject"]').forEach((button) => {
      if (button.dataset.id === key) button.disabled = true;
    });

    const actionName = decision === "Approved" ? "approve" : "reject";
    try {
      const res = await apiCall(`requests.php?action=${actionName}`, {
        method: "POST",
        body: JSON.stringify({ id })
      });

      if (res?.success) {
        request.status = decision;
        if (decision === "Approved") {
          request.conditionOut = res.conditionOut || itemFor(request)?.condition || "Good";
          const equipment = itemFor(request);
          if (equipment) {
            const available = Number(res.available);
            if (Number.isFinite(available)) equipment.available = available;
            equipment.status = res.equipmentStatus || (equipment.available > 0 ? "Available" : "Borrowed");
          }
          data.activity.unshift({
            id: makeId(),
            icon: "✓",
            text: `${activeUser.name} approved ${equipment?.name || "equipment"}`,
            date: offsetDate(0)
          });
        } else {
          data.activity.unshift({
            id: makeId(),
            icon: "×",
            text: `${activeUser.name} declined a borrowing request`,
            date: offsetDate(0)
          });
        }
        persist(data);
        renderAll();
        showToast(res.message || (decision === "Approved" ? "Request approved." : "Request declined."));
      } else {
        showToast(res?.message || `Could not ${decision.toLowerCase()} request.`, true);
      }
    } finally {
      pendingRequestReviews.delete(key);
      document.querySelectorAll('[data-action="approve"], [data-action="reject"]').forEach((button) => {
        if (button.dataset.id === key) button.disabled = false;
      });
    }
  }

  function openReturnModal(id) {
    if (!canAccessStewardDashboard(activeUser)) { showToast("Only community stewards can record returns.", true); return; }
    const request = data.requests.find((item) => String(item.id) === String(id));
    if (!request || !isActiveBorrowing(request)) return;
    get("return-form").reset();
    get("return-form").elements.requestId.value = request.id;
    get("return-copy").textContent = `Checking in ${itemFor(request)?.name || "equipment"} from ${request.borrower}. Please note its condition.`;
    bootstrap.Modal.getOrCreateInstance(get("return-modal")).show();
  }

  // ── RECORD RETURN / CHECKIN ────────────────────────────────────────────────
  async function recordReturn(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    const id = String(values.get("requestId"));
    const condition = String(values.get("condition"));
    const notes = String(values.get("notes") || "").trim();

    const res = await apiCall("requests.php?action=return", {
      method: "POST",
      body: JSON.stringify({ id, condition, notes })
    });

    if (res && res.success) {
      bootstrap.Modal.getOrCreateInstance(get("return-modal")).hide();
      showToast(res.message || "Return recorded and condition noted.");
      await syncWithServer();
    } else {
      showToast(res?.message || "That change could not be completed. Please try again.", true);
    }
  }

  // ── REMOVE EQUIPMENT ───────────────────────────────────────────────────────
  function removeEquipment(id) {
    if (activeUser.role !== "Administrator") { showToast("Only administrators can remove equipment.", true); return; }
    const item = data.equipment.find((entry) => String(entry.id) === String(id));
    if (!item) return;
    if (data.requests.some((request) => String(request.equipmentId) === String(id) && isActiveBorrowing(request))) {
      showToast("This equipment is currently borrowed and cannot be removed.", true);
      return;
    }
    confirmAction("Remove equipment?", `Remove ${item.name} from the collection? Its borrowing history will remain in reports.`, async () => {
      const res = await apiCall("equipment.php?action=delete", {
        method: "POST",
        body: JSON.stringify({ id: item.id })
      });

      if (res && res.success) {
        bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).hide();
        showToast(res.message || "Equipment removed from the collection.");
        await syncWithServer();
      } else {
        showToast(res?.message || "That change could not be completed. Please try again.", true);
      }
    });
  }

  function setMemberAccountModal() {
    const select = get("member-account-select");
    if (!select) return;
    select.innerHTML = data.people.map((person) => `<option value="${escapeHtml(person.email)}"${person.email.toLowerCase() === activeUser.email.toLowerCase() ? " selected" : ""}>${escapeHtml(person.name)} · ${escapeHtml(person.role)}</option>`).join("");
    bootstrap.Modal.getOrCreateInstance(get("account-modal")).show();
  }

  function initAdmin() {
    const steward = data.people.find((person) => person.email?.toLowerCase() === activeUser.email?.toLowerCase() && canAccessStewardDashboard(person));
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
        syncWithServer();
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
    const member = data.people.find((person) => person.email?.toLowerCase() === activeUser.email?.toLowerCase() && person.role === "Member");
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
      if (action.dataset.action === "edit-equipment") openEquipmentForm(data.equipment.find((item) => String(item.id) === String(id)));
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
        const person = data.people.find((entry) => String(entry.id) === String(id));
        if (!person || person.email.toLowerCase() === activeUser.email.toLowerCase()) return;
        confirmAction("Remove this person?", `Remove ${person.name} from the community? Borrowing history will remain in reports.`, async () => {
          const res = await apiCall("members.php?action=delete", {
            method: "POST",
            body: JSON.stringify({ id: person.id })
          });
          if (res && res.success) {
            bootstrap.Modal.getOrCreateInstance(get("confirm-modal")).hide();
            showToast(res.message || "Person removed from the community.");
            await syncWithServer();
          } else {
            showToast(res?.message || "Could not remove person.", true);
          }
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
        syncWithServer();
      });
      get("equipment-search").addEventListener("input", renderAdminEquipment);
      get("equipment-category").addEventListener("change", renderAdminEquipment);
      get("equipment-status").addEventListener("change", renderAdminEquipment);
      get("request-search").addEventListener("input", renderAdminRequests);
      get("request-status").addEventListener("change", renderAdminRequests);
      get("report-period").addEventListener("change", renderReports);
      get("edit-photo").addEventListener("input", (event) => {
        const input = event.currentTarget;
        showPhotoPreview(input.validity.valid ? input.value : "");
      });
      get("edit-quantity").addEventListener("input", () => { get("edit-available").max = get("edit-quantity").value; });

      // Save equipment form (Add or Edit)
      get("edit-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        if (activeUser.role !== "Administrator") { showToast("Only administrators can change equipment.", true); return; }
        const values = new FormData(form);
        const quantity = Number(values.get("quantity"));
        const available = Number(values.get("available"));
        const id = String(values.get("id") || "");
        if (quantity < 0 || available < 0 || available > quantity) { showToast("Available quantity must be between zero and the total.", true); return; }
        const name = String(values.get("name")).trim();

        const payload = {
          id,
          action: id ? "update" : "create",
          name,
          category: String(values.get("category")),
          quantity,
          available,
          emoji: String(values.get("emoji")).trim() || "📦",
          condition: String(values.get("condition")),
          status: String(values.get("status")),
          description: String(values.get("description")).trim(),
          photo: String(values.get("photo") || "").trim()
        };

        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        const res = await apiCall("equipment.php", { method: "POST", body: JSON.stringify(payload) });
        submitButton.disabled = false;

        if (res && res.success) {
          bootstrap.Modal.getOrCreateInstance(get("edit-modal")).hide();
          showToast(res.message || (id ? "Equipment details saved." : "Added to the community collection."));
          await syncWithServer();
        } else {
          showToast(res?.message || "That change could not be completed. Please try again.", true);
        }
      });

      // Add member form
      get("member-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const values = new FormData(form);
        const name = String(values.get("name")).trim();
        const email = String(values.get("email")).trim().toLowerCase();
        const role = String(values.get("role"));

        const res = await apiCall("members.php?action=create", {
          method: "POST",
          body: JSON.stringify({ name, email, role })
        });

        if (res && res.success) {
          bootstrap.Modal.getOrCreateInstance(get("member-modal")).hide();
          showToast(res.message || `${name} has been added to the community.`);
          await syncWithServer();
        } else {
          showToast(res?.message || "A person with this email is already in the community.", true);
        }
      });

      get("return-form").addEventListener("submit", recordReturn);

      // Change role select
      get("admin-members-table").addEventListener("change", async (event) => {
        const select = event.target.closest('[data-action="change-role"]');
        if (!select || activeUser.role !== "Administrator") return;
        const personId = select.dataset.id;
        const newRole = select.value;

        const res = await apiCall("members.php?action=update_role", {
          method: "POST",
          body: JSON.stringify({ id: personId, role: newRole })
        });

        if (res && res.success) {
          showToast(res.message || "Role updated.");
          await syncWithServer();
        } else {
          showToast(res?.message || "Keep at least one administrator on your own account.", true);
          renderAdminMembers();
        }
      });

      // Native CSV Exports generated by PHP controller
      get("export-requests").addEventListener("click", () => {
        window.location.href = `${API_BASE}/reports.php?action=export_csv&type=requests`;
      });
      get("export-report").addEventListener("click", () => {
        const period = get("report-period") ? get("report-period").value : "all";
        window.location.href = `${API_BASE}/reports.php?action=export_csv&type=report&period=${encodeURIComponent(period)}`;
      });

      get("confirm-remove").addEventListener("click", () => { if (confirmCallback) confirmCallback(); });
      get("confirm-modal").addEventListener("hidden.bs.modal", () => { confirmCallback = null; });

      if (get("admin-sign-out")) {
        get("admin-sign-out").addEventListener("click", async () => {
          try {
            await apiCall("session.php?action=logout", { method: "POST" });
            sessionStorage.removeItem("gamitshare-php-user");
            localStorage.removeItem(SESSION_KEY);
            location.href = "../auth/login.html";
          } catch (error) {
            location.href = "../auth/login.html";
          }
        });
      }
    } else {
      get("member-search").addEventListener("input", renderMemberBrowse);
      get("member-category").addEventListener("change", renderMemberBrowse);
      get("member-availability").addEventListener("change", renderMemberBrowse);
      get("borrow-equipment").addEventListener("change", () => {
        const item = data.equipment.find((entry) => String(entry.id) === String(get("borrow-equipment").value));
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
      if (get("member-account-button")) get("member-account-button").addEventListener("click", openAccount);
      if (get("member-account-top")) get("member-account-top").addEventListener("click", openAccount);
      if (get("switch-member")) {
        get("switch-member").addEventListener("click", () => {
          const person = data.people.find((entry) => entry.email === get("member-account-select").value);
          if (!person) return;
          setSession(person);
          bootstrap.Modal.getOrCreateInstance(get("account-modal")).hide();
          renderAll();
          showToast(`Viewing GamitShare as ${person.name}.`);
          syncWithServer();
        });
      }
      if (get("member-sign-out")) {
        get("member-sign-out").addEventListener("click", async () => {
          try {
            await apiCall("session.php?action=logout", { method: "POST" });
            sessionStorage.removeItem("gamitshare-php-user");
            localStorage.removeItem(SESSION_KEY);
            location.href = "../auth/login.html";
          } catch (error) {
            location.href = "../auth/login.html";
          }
        });
      }
    }
  }

  async function init() {
    data = readData();
    if (!data.people.length) data.people = defaultData().people;
    mergeLocalAccounts();
    activeUser = readSession();

    // Verify session with server
    try {
      const sessionRes = await fetch(`${API_BASE}/session.php`, { credentials: "same-origin" });
      const sessionData = await sessionRes.json();
      if (sessionData && sessionData.success && sessionData.user) {
        activeUser = sessionData.user;
        sessionStorage.setItem("gamitshare-php-user", JSON.stringify(activeUser));
      } else if (sessionData && sessionData.authenticated === false) {
        // Not authenticated on server
        if (!isAdminPage) {
          sessionStorage.removeItem("gamitshare-php-user");
          localStorage.removeItem(SESSION_KEY);
          location.replace("../auth/login.html");
          return;
        }
      }
    } catch (e) {
      console.warn("[GamitShare] Could not verify session with server:", e);
    }
    if (!activeUser) {
      if (!isAdminPage) {
        location.replace("../auth/login.html");
        return;
      }
      activeUser = data.people.find((person) => person.role === "Administrator");
      if (!activeUser) activeUser = data.people[0];
      if (activeUser) {
        try { localStorage.setItem(SESSION_KEY, JSON.stringify({ name: activeUser.name, email: activeUser.email, role: activeUser.role })); } catch (error) {}
      }
    }
    if (!activeUser) {
      showToast("No community accounts are available.", true);
      return;
    }
    initEvents();
    if (isAdminPage) initAdmin();
    else initMember();

    // Asynchronously sync with MySQL database via controllers
    await syncWithServer();
  }

  init();
})();
