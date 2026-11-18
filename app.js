// КГЭУ Расписание - Управление данными и интерфейсом

const DEFAULT_GROUP_ID = 14493;
let currentGroupId = DEFAULT_GROUP_ID;

// Получение реальной сегодняшней даты браузера (YYYY-MM-DD)
function getTodayDateStr() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

// Форматирование: [День недели] - [dd:mm:yyyy]
function formatCustomDateTitle(isoString) {
  if (!isoString) return '';
  const date = new Date(isoString);
  const days = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
  const dayName = days[date.getDay()];
  const dd = String(date.getDate()).padStart(2, '0');
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const yyyy = date.getFullYear();
  return `${dayName} - ${dd}:${mm}:${yyyy}`;
}

function formatCustomWeekTitle(startDateStr, endDateStr) {
  const d1 = new Date(startDateStr);
  const d2 = new Date(endDateStr);
  const dd1 = String(d1.getDate()).padStart(2, '0');
  const mm1 = String(d1.getMonth() + 1).padStart(2, '0');
  const yyyy1 = d1.getFullYear();
  const dd2 = String(d2.getDate()).padStart(2, '0');
  const mm2 = String(d2.getMonth() + 1).padStart(2, '0');
  const yyyy2 = d2.getFullYear();
  return `Вся неделя - ${dd1}:${mm1}:${yyyy1} – ${dd2}:${mm2}:${yyyy2}`;
}

function formatShortDate(isoString) {
  if (!isoString) return '';
  const date = new Date(isoString);
  const dd = String(date.getDate()).padStart(2, '0');
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  return `${dd}.${mm}`;
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Состояние приложения
let currentViewMode = 'day'; // 'day' или 'week'
let selectedDate = getTodayDateStr(); // По умолчанию выбирается СЕГОДНЯ
let activeTypeFilter = 'all';

// Для календаря
const nowInit = new Date();
let calendarMonth = new Date(nowInit.getFullYear(), nowInit.getMonth(), 1);

// Полная база всех загруженных пар за семестр
let allMonthRaspMap = {};
let scheduleInfo = null;

// Состояние заметок и TODO чекбоксов
let todoData = {
  checkboxes: {},     // 'key': boolean
  customNotes: [],    // [ { id, pairKey, date, text, completed } ]
  editedDefaults: {}, // 'key': 'Новое название'
  deletedDefaults: [] // [ 'key' ]
};

// Хронология лабораторных работ семестра (каждый день = 1 лаба на 2 подряд идущие пары)
const SUBJECT_LAB_TIMELINE = {
  'Исследование операций': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-03', '2026-09-16', '2026-09-30', '2026-10-14', '2026-10-28', '2026-11-11', '2026-11-25', '2026-12-09']
  },
  'Маркетинг ИТ-продуктов': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-10', '2026-09-24', '2026-10-08', '2026-10-22', '2026-11-05', '2026-11-19', '2026-12-03', '2026-12-17']
  },
  'Сетевое планирование и управление ИТ-проектами': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-11', '2026-09-25', '2026-10-09', '2026-10-23', '2026-11-06', '2026-11-20', '2026-12-04', '2026-12-18']
  },
  'Интерфейс программных продуктов': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-12', '2026-09-26', '2026-10-10', '2026-10-24', '2026-11-07', '2026-11-21', '2026-12-05', '2026-12-19']
  },
  'Web-программирование': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
  },
  'Статистика': {
    unit: 'Лабораторная работа',
    dates: ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
  },
  'Оценка эффективности ИТ-проектов': {
    unit: 'Кейс',
    dates: ['2026-09-18', '2026-10-02', '2026-10-16', '2026-10-30', '2026-11-13', '2026-11-27', '2026-12-11']
  }
};

// Парсинг дисциплины и типа
function parseDiscipline(rawName) {
  if (!rawName) {
    return { type: 'Занятие', typeKey: 'other', title: '—', badgeClass: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' };
  }
  
  const trimmed = rawName.trim();
  if (trimmed.startsWith('лек ')) {
    return {
      type: 'Лекция',
      typeKey: 'lecture',
      title: trimmed.replace(/^лек\s+/, ''),
      badgeClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    };
  } else if (trimmed.startsWith('лаб ')) {
    return {
      type: 'Лабораторная',
      typeKey: 'lab',
      title: trimmed.replace(/^лаб\s+/, ''),
      badgeClass: 'bg-purple-500/10 text-purple-600 dark:text-purple-400'
    };
  } else if (trimmed.startsWith('пр ')) {
    return {
      type: 'Практика',
      typeKey: 'practice',
      title: trimmed.replace(/^пр\s+/, ''),
      badgeClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    };
  } else if (trimmed.startsWith('зач ')) {
    return {
      type: 'Зачет',
      typeKey: 'exam',
      title: trimmed.replace(/^зач\s+/, ''),
      badgeClass: 'bg-red-500/10 text-red-600 dark:text-red-400'
    };
  } else if (trimmed.startsWith('экз ')) {
    return {
      type: 'Экзамен',
      typeKey: 'exam',
      title: trimmed.replace(/^экз\s+/, ''),
      badgeClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
    };
  }
  
  return {
    type: 'Занятие',
    typeKey: 'other',
    title: trimmed,
    badgeClass: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
  };
}

// -------------------------------------------------------------
// Управление состоянием TODO и заметок
// -------------------------------------------------------------
async function loadTodoStates() {
  const localSaved = localStorage.getItem('kgeu_todo_data_v2') || localStorage.getItem('kgeu_todo_state');
  if (localSaved) {
    try {
      const parsed = JSON.parse(localSaved);
      if (parsed && typeof parsed === 'object') {
        if (parsed.checkboxes || parsed.customNotes) {
          todoData = {
            checkboxes: parsed.checkboxes || {},
            customNotes: parsed.customNotes || [],
            editedDefaults: parsed.editedDefaults || {},
            deletedDefaults: parsed.deletedDefaults || []
          };
        } else {
          todoData.checkboxes = { ...parsed };
        }
      }
    } catch {}
  }

  try {
    const res = await fetch('/api/todo');
    if (res.ok) {
      const serverState = await res.json();
      if (serverState && typeof serverState === 'object') {
        if (serverState.checkboxes || serverState.customNotes) {
          todoData.checkboxes = { ...todoData.checkboxes, ...(serverState.checkboxes || {}) };
          todoData.customNotes = serverState.customNotes || todoData.customNotes;
          todoData.editedDefaults = { ...todoData.editedDefaults, ...(serverState.editedDefaults || {}) };
          todoData.deletedDefaults = Array.from(new Set([...todoData.deletedDefaults, ...(serverState.deletedDefaults || [])]));
        } else {
          todoData.checkboxes = { ...todoData.checkboxes, ...serverState };
        }
        localStorage.setItem('kgeu_todo_data_v2', JSON.stringify(todoData));
      }
    }
  } catch {}
}

async function saveTodoData() {
  localStorage.setItem('kgeu_todo_data_v2', JSON.stringify(todoData));
  try {
    fetch('/api/todo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(todoData)
    }).catch(() => {});
  } catch {}
}

function toggleCheckbox(key, isChecked) {
  todoData.checkboxes[key] = isChecked;
  saveTodoData();
  renderScheduleCards();
}

function toggleCustomNote(id, isChecked) {
  const n = (todoData.customNotes || []).find(item => item.id === id);
  if (n) {
    n.completed = isChecked;
    saveTodoData();
    renderScheduleCards();
  }
}

function deleteCustomNote(id) {
  todoData.customNotes = (todoData.customNotes || []).filter(item => item.id !== id);
  saveTodoData();
  renderScheduleCards();
}

function deleteDefaultLab(todoKey) {
  if (!todoData.deletedDefaults) todoData.deletedDefaults = [];
  if (!todoData.deletedDefaults.includes(todoKey)) {
    todoData.deletedDefaults.push(todoKey);
  }
  saveTodoData();
  renderScheduleCards();
}

// -------------------------------------------------------------
// Модальное окно для создания / редактирования заметок и лаб
// -------------------------------------------------------------
function openAddPairNoteModal(pairKey, pairDate) {
  const modal = document.getElementById('note-modal');
  const title = document.getElementById('note-modal-title');
  const mode = document.getElementById('note-modal-mode');
  const targetType = document.getElementById('note-modal-target-type');
  const idInput = document.getElementById('note-modal-id');
  const pairKeyInput = document.getElementById('note-modal-pairkey');
  const dateInput = document.getElementById('note-modal-date');
  const input = document.getElementById('note-modal-input');

  title.textContent = 'Добавить задачу / лабу к занятию';
  mode.value = 'add';
  targetType.value = 'pair';
  idInput.value = '';
  pairKeyInput.value = pairKey;
  dateInput.value = pairDate;
  input.value = '';

  modal.classList.remove('hidden');
  setTimeout(() => input.focus(), 50);
}

function openAddDayNoteModal() {
  const modal = document.getElementById('note-modal');
  const title = document.getElementById('note-modal-title');
  const mode = document.getElementById('note-modal-mode');
  const targetType = document.getElementById('note-modal-target-type');
  const idInput = document.getElementById('note-modal-id');
  const pairKeyInput = document.getElementById('note-modal-pairkey');
  const dateInput = document.getElementById('note-modal-date');
  const input = document.getElementById('note-modal-input');

  title.textContent = `Добавить заметку на день (${formatShortDate(selectedDate)})`;
  mode.value = 'add';
  targetType.value = 'day';
  idInput.value = '';
  pairKeyInput.value = '';
  dateInput.value = selectedDate;
  input.value = '';

  modal.classList.remove('hidden');
  setTimeout(() => input.focus(), 50);
}

function openEditCustomNoteModal(id) {
  const note = (todoData.customNotes || []).find(n => n.id === id);
  if (!note) return;

  const modal = document.getElementById('note-modal');
  const title = document.getElementById('note-modal-title');
  const mode = document.getElementById('note-modal-mode');
  const targetType = document.getElementById('note-modal-target-type');
  const idInput = document.getElementById('note-modal-id');
  const input = document.getElementById('note-modal-input');

  title.textContent = 'Редактировать заметку';
  mode.value = 'edit';
  targetType.value = 'custom';
  idInput.value = id;
  input.value = note.text || '';

  modal.classList.remove('hidden');
  setTimeout(() => input.focus(), 50);
}

function openEditDefaultLabModal(todoKey, defaultText) {
  const currentText = todoData.editedDefaults[todoKey] || defaultText;

  const modal = document.getElementById('note-modal');
  const title = document.getElementById('note-modal-title');
  const mode = document.getElementById('note-modal-mode');
  const targetType = document.getElementById('note-modal-target-type');
  const idInput = document.getElementById('note-modal-id');
  const input = document.getElementById('note-modal-input');

  title.textContent = 'Редактировать название лабораторной';
  mode.value = 'edit';
  targetType.value = 'default_lab';
  idInput.value = todoKey;
  input.value = currentText;

  modal.classList.remove('hidden');
  setTimeout(() => input.focus(), 50);
}

function closeNoteModal() {
  const modal = document.getElementById('note-modal');
  if (modal) modal.classList.add('hidden');
}

function handleNoteModalSubmit(event) {
  event.preventDefault();
  const input = document.getElementById('note-modal-input');
  const mode = document.getElementById('note-modal-mode').value;
  const targetType = document.getElementById('note-modal-target-type').value;
  const id = document.getElementById('note-modal-id').value;
  const pairKey = document.getElementById('note-modal-pairkey').value;
  const date = document.getElementById('note-modal-date').value;
  const text = input.value.trim();

  if (!text) return;

  if (mode === 'add') {
    const newNote = {
      id: 'note_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
      pairKey: targetType === 'pair' ? pairKey : null,
      date: date || selectedDate,
      text: text,
      completed: false
    };
    if (!todoData.customNotes) todoData.customNotes = [];
    todoData.customNotes.push(newNote);
  } else if (mode === 'edit') {
    if (targetType === 'custom') {
      const note = (todoData.customNotes || []).find(n => n.id === id);
      if (note) note.text = text;
    } else if (targetType === 'default_lab') {
      if (!todoData.editedDefaults) todoData.editedDefaults = {};
      todoData.editedDefaults[id] = text;
    }
  }

  saveTodoData();
  closeNoteModal();
  renderScheduleCards();
}

// -------------------------------------------------------------
// Определение номера лабы для конкретной пары
// -------------------------------------------------------------
function getLabInfoForPair(subjectTitle, dateStr) {
  let matchedSubj = null;
  const cleanTitle = subjectTitle.toLowerCase();

  for (const key of Object.keys(SUBJECT_LAB_TIMELINE)) {
    const kLow = key.toLowerCase();
    if (cleanTitle.includes(kLow) || kLow.includes(cleanTitle)) {
      matchedSubj = key;
      break;
    }
    if (cleanTitle.includes('сетевое') && kLow.includes('сетевое')) { matchedSubj = key; break; }
    if (cleanTitle.includes('маркетинг') && kLow.includes('маркетинг')) { matchedSubj = key; break; }
    if (cleanTitle.includes('интерфейс') && kLow.includes('интерфейс')) { matchedSubj = key; break; }
    if (cleanTitle.includes('web') && kLow.includes('web')) { matchedSubj = key; break; }
    if (cleanTitle.includes('оценка') && kLow.includes('оценка')) { matchedSubj = key; break; }
    if (cleanTitle.includes('исследование') && kLow.includes('исследование')) { matchedSubj = key; break; }
    if (cleanTitle.includes('статистик') && kLow.includes('статистик')) { matchedSubj = key; break; }
  }

  if (!matchedSubj) return null;

  const info = SUBJECT_LAB_TIMELINE[matchedSubj];
  const unit = info.unit || 'Лабораторная работа';
  const dates = info.dates || [];

  let labNum = 1;
  const idx = dates.indexOf(dateStr);
  if (idx >= 0) {
    labNum = idx + 1;
  } else {
    let count = 0;
    for (const d of dates) {
      if (d <= dateStr) count++;
    }
    labNum = count > 0 ? count : 1;
  }

  const todoKey = `${matchedSubj}_${labNum}`;
  const isChecked = !!(todoData.checkboxes && todoData.checkboxes[todoKey]);

  return {
    matchedSubj,
    unit,
    labNum,
    todoKey,
    isChecked,
    label: `${unit} №${labNum}`
  };
}

// Уникальный ключ пары против дублирования
function getPairKey(item) {
  const dateStr = item.дата ? item.дата.split('T')[0] : '';
  const num = item.номерЗанятия || '';
  const time = (item.начало || '').trim();
  const sub = item.номерПодгруппы || 0;
  const disc = (item.дисциплина || '').trim().toLowerCase();
  return `${dateStr}_${num}_${time}_${sub}_${disc}`;
}

// Слияние пар БЕЗ дублирования
function mergeRaspItems(items) {
  if (!Array.isArray(items)) return;
  items.forEach(item => {
    if (!item.дата) return;
    const dateKey = item.дата.split('T')[0];
    if (!allMonthRaspMap[dateKey]) {
      allMonthRaspMap[dateKey] = [];
    }

    const itemKey = getPairKey(item);
    const exists = allMonthRaspMap[dateKey].some(it => getPairKey(it) === itemKey);
    if (!exists) {
      allMonthRaspMap[dateKey].push(item);
    }
  });
}

// Понедельник текущей недели
function getMondayOf(dateString) {
  const d = new Date(dateString);
  const day = d.getDay();
  const diff = (day === 0 ? -6 : 1) - day;
  d.setDate(d.getDate() + diff);
  return d.toISOString().split('T')[0];
}

// -------------------------------------------------------------
// Загрузка полного расписания на весь семестр
// -------------------------------------------------------------
async function initialLoad() {
  setLoadingState(true);

  // 1. Быстро читаем локальный кэш семестра, если он есть
  const cacheCandidates = [
    `./data/schedule_semester_${currentGroupId}.json`,
    './data/schedule_semester_cache.json',
    './data/schedule_sample.json'
  ];

  for (const path of cacheCandidates) {
    try {
      const localRes = await fetch(path);
      if (localRes.ok) {
        const localData = await localRes.json();
        if (localData?.data?.rasp && localData.data.rasp.length > 0) {
          if (localData.data.info) scheduleInfo = localData.data.info;
          mergeRaspItems(localData.data.rasp);
          renderApp();
          break;
        }
      }
    } catch {}
  }

  // 2. Загружаем полное расписание с сервера на чистом PHP (/api/full-schedule)
  try {
    const res = await fetch(`/api/full-schedule?idGroup=${currentGroupId}`);
    if (res.ok) {
      const fullData = await res.json();
      if (fullData?.data?.rasp) {
        mergeRaspItems(fullData.data.rasp);
        if (fullData.data.info) scheduleInfo = fullData.data.info;
        renderApp();
      }
    } else {
      // Резервный запрос одной недели
      const currentMonday = getMondayOf(selectedDate);
      const weekData = await fetchWeekData(currentGroupId, currentMonday);
      if (weekData?.rasp) {
        mergeRaspItems(weekData.rasp);
        if (weekData.info) scheduleInfo = weekData.info;
        renderApp();
      }
    }
  } catch (e) {
    console.warn('Сетевой запрос не удался:', e);
  } finally {
    setLoadingState(false);
    updateSyncTime();
  }
}

// Ручное обновление при клике на кнопку "Обновить"
async function manualRefresh() {
  setLoadingState(true);
  try {
    const res = await fetch(`/api/full-schedule?idGroup=${currentGroupId}&refresh=1`);
    if (res.ok) {
      const fullData = await res.json();
      if (fullData?.data?.rasp) {
        mergeRaspItems(fullData.data.rasp);
        if (fullData.data.info) scheduleInfo = fullData.data.info;
        renderApp();
      }
    } else {
      const targetMonday = getMondayOf(selectedDate);
      const weekData = await fetchWeekData(currentGroupId, targetMonday);
      if (weekData?.rasp) {
        mergeRaspItems(weekData.rasp);
        if (weekData.info) scheduleInfo = weekData.info;
        renderApp();
      }
    }
  } catch (e) {
    console.warn('Ошибка при ручном обновлении:', e);
  } finally {
    setLoadingState(false);
    updateSyncTime();
  }
}

async function fetchWeekData(idGroup, sdate) {
  const proxyUrl = `/api/Rasp?idGroup=${idGroup}&sdate=${sdate}`;
  const directUrl = `https://kabinet.kgeu.ru/api/Rasp?idGroup=${idGroup}&sdate=${sdate}`;

  try {
    let res;
    try {
      res = await fetch(proxyUrl);
      if (!res.ok) throw new Error('Proxy status ' + res.status);
    } catch {
      res = await fetch(directUrl);
    }
    const json = await res.json();
    return json?.data;
  } catch {
    return null;
  }
}

function setLoadingState(isLoading) {
  const btn = document.getElementById('refresh-btn');
  const icon = document.getElementById('refresh-icon');
  if (btn && icon) {
    if (isLoading) {
      icon.classList.add('animate-spin');
      btn.disabled = true;
    } else {
      icon.classList.remove('animate-spin');
      btn.disabled = false;
    }
  }
}

function updateSyncTime() {
  const el = document.getElementById('sync-status');
  if (el) {
    const time = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    el.textContent = `Обновлено в ${time}`;
  }
}

function isPairCurrent(startIso, endIso) {
  if (!startIso || !endIso) return false;
  const now = new Date();
  const start = new Date(startIso);
  const end = new Date(endIso);
  return now >= start && now <= end;
}

function getWeekDates(targetDateStr) {
  const target = new Date(targetDateStr);
  const day = target.getDay();
  const diff = (day === 0 ? -6 : 1) - day;
  const monday = new Date(target);
  monday.setDate(target.getDate() + diff);

  const week = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(monday);
    d.setDate(monday.getDate() + i);
    week.push(d.toISOString().split('T')[0]);
  }
  return week;
}

function renderApp() {
  renderCalendar();
  renderScheduleCards();
  updateWeekToggleBtn();
}

// -------------------------------------------------------------
// Рендер календаря
// -------------------------------------------------------------
function renderCalendar() {
  const container = document.getElementById('calendar-grid');
  const monthTitle = document.getElementById('calendar-month-title');
  if (!container || !monthTitle) return;

  const year = calendarMonth.getFullYear();
  const month = calendarMonth.getMonth();

  monthTitle.textContent = calendarMonth.toLocaleDateString('ru-RU', {
    month: 'long',
    year: 'numeric'
  }).replace(' г.', '');

  const firstDayOfMonth = new Date(year, month, 1);
  const lastDayOfMonth = new Date(year, month + 1, 0);

  let startingDayOfWeek = firstDayOfMonth.getDay();
  if (startingDayOfWeek === 0) startingDayOfWeek = 7;

  const totalDays = lastDayOfMonth.getDate();
  const weekDatesOfSelected = currentViewMode === 'week' ? getWeekDates(selectedDate) : [];
  const realTodayStr = getTodayDateStr();

  let html = '';

  for (let i = 1; i < startingDayOfWeek; i++) {
    html += `<div class="aspect-square"></div>`;
  }

  for (let day = 1; day <= totalDays; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const pairsForDay = allMonthRaspMap[dateStr] || [];
    
    const isSelected = (selectedDate === dateStr);
    const isWeekHighlighted = (currentViewMode === 'week' && weekDatesOfSelected.includes(dateStr));
    const isToday = (dateStr === realTodayStr);

    let dotsHtml = '';
    if (pairsForDay.length > 0) {
      const typeSet = new Set();
      pairsForDay.forEach(p => {
        const d = parseDiscipline(p.дисциплина);
        typeSet.add(d.typeKey);
      });

      dotsHtml = `<div class="flex items-center justify-center gap-0.5 mt-0.5">`;
      if (typeSet.has('lecture')) dotsHtml += `<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>`;
      if (typeSet.has('lab')) dotsHtml += `<span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>`;
      if (typeSet.has('practice')) dotsHtml += `<span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>`;
      if (typeSet.has('exam')) dotsHtml += `<span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>`;
      dotsHtml += `</div>`;
    }

    let bgClass = 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300';
    if (isSelected) {
      bgClass = 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/30';
    } else if (isWeekHighlighted) {
      bgClass = 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold';
    }

    const todayRing = isToday && !isSelected ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-slate-900 font-bold' : '';

    html += `
      <button 
        onclick="selectCalendarDate('${dateStr}')" 
        class="aspect-square rounded-xl p-1 flex flex-col items-center justify-center text-xs transition relative ${bgClass} ${todayRing}"
      >
        <span>${day}</span>
        ${dotsHtml}
      </button>
    `;
  }

  container.innerHTML = html;
}

async function selectCalendarDate(dateStr) {
  selectedDate = dateStr;
  currentViewMode = 'day';
  renderApp();

  // Если для выбранного дня в кэше еще нет данных — подгружаем эту неделю на лету
  if (!allMonthRaspMap[dateStr] || allMonthRaspMap[dateStr].length === 0) {
    const monday = getMondayOf(dateStr);
    const weekData = await fetchWeekData(currentGroupId, monday);
    if (weekData?.rasp && weekData.rasp.length > 0) {
      mergeRaspItems(weekData.rasp);
      renderApp();
    }
  }
}

function toggleWeekView() {
  currentViewMode = currentViewMode === 'week' ? 'day' : 'week';
  renderApp();
}

function updateWeekToggleBtn() {
  const btn = document.getElementById('toggle-week-btn');
  const text = document.getElementById('toggle-week-btn-text');
  if (!btn || !text) return;

  if (currentViewMode === 'week') {
    text.textContent = 'Показать один день';
    btn.classList.add('bg-indigo-600', 'text-white');
    btn.classList.remove('bg-indigo-50', 'dark:bg-indigo-950/50', 'text-indigo-600', 'dark:text-indigo-400');
  } else {
    text.textContent = 'Показать всю неделю';
    btn.classList.remove('bg-indigo-600', 'text-white');
    btn.classList.add('bg-indigo-50', 'dark:bg-indigo-950/50', 'text-indigo-600', 'dark:text-indigo-400');
  }
}

function prevMonth() {
  calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
  renderCalendar();
}

function nextMonth() {
  calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
  renderCalendar();
}

function resetToToday() {
  selectedDate = getTodayDateStr();
  const now = new Date();
  calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  currentViewMode = 'day';
  renderApp();
}

// -------------------------------------------------------------
// Рендер общих заметок дня
// -------------------------------------------------------------
function renderDayNotes(dateStr) {
  const container = document.getElementById('day-notes-container');
  if (!container) return;

  if (currentViewMode !== 'day') {
    container.classList.add('hidden');
    container.innerHTML = '';
    return;
  }

  const dayNotes = (todoData.customNotes || []).filter(n => n.date === dateStr && !n.pairKey);

  if (dayNotes.length === 0) {
    container.classList.add('hidden');
    container.innerHTML = '';
    return;
  }

  container.classList.remove('hidden');
  container.innerHTML = `
    <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 shadow-sm border border-indigo-100/60 dark:border-indigo-950/40 mb-3">
      <div class="flex items-center justify-between mb-2.5 pb-2 border-b border-slate-100 dark:border-slate-800">
        <div class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200">
          <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
          <span>Заметки на этот день (${dayNotes.length})</span>
        </div>
        <button onclick="openAddDayNoteModal()" class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
          + Добавить
        </button>
      </div>
      <div class="space-y-1.5">
        ${dayNotes.map(n => `
          <div class="flex items-center justify-between group/note py-1.5 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
            <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
              <input 
                type="checkbox" 
                ${n.completed ? 'checked' : ''} 
                onchange="toggleCustomNote('${n.id}', this.checked)"
                class="w-4 h-4 rounded text-indigo-600 accent-indigo-600 cursor-pointer transition"
              >
              <span class="text-xs font-medium truncate ${n.completed ? 'line-through text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-200'}">
                ${escapeHtml(n.text)}
              </span>
            </label>
            <div class="flex items-center gap-1 opacity-80 group-hover/note:opacity-100 transition shrink-0">
              <button onclick="openEditCustomNoteModal('${n.id}')" title="Редактировать" class="p-1 rounded text-slate-400 hover:text-indigo-600 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
              </button>
              <button onclick="deleteCustomNote('${n.id}')" title="Удалить" class="p-1 rounded text-slate-400 hover:text-rose-600 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
}

// -------------------------------------------------------------
// Рендер карточек расписания
// -------------------------------------------------------------
function renderScheduleCards() {
  const container = document.getElementById('schedule-cards-container');
  const viewTitle = document.getElementById('current-view-title');

  renderDayNotes(selectedDate);

  let activePairs = [];

  if (currentViewMode === 'day') {
    activePairs = allMonthRaspMap[selectedDate] || [];
  } else {
    const weekDates = getWeekDates(selectedDate);
    weekDates.forEach(d => {
      const dayPairs = allMonthRaspMap[d] || [];
      activePairs.push(...dayPairs);
    });
  }

  // Дедупликация
  const uniqueMap = new Map();
  activePairs.forEach(item => {
    const key = getPairKey(item);
    if (!uniqueMap.has(key)) {
      uniqueMap.set(key, item);
    }
  });
  activePairs = Array.from(uniqueMap.values());

  // Фильтр по типам
  let filtered = activePairs.filter(item => {
    const discInfo = parseDiscipline(item.дисциплина);
    if (activeTypeFilter !== 'all' && discInfo.typeKey !== activeTypeFilter) {
      return false;
    }
    return true;
  });

  // Заголовок
  if (viewTitle) {
    if (currentViewMode === 'day') {
      viewTitle.textContent = formatCustomDateTitle(selectedDate);
    } else {
      const weekDates = getWeekDates(selectedDate);
      viewTitle.textContent = formatCustomWeekTitle(weekDates[0], weekDates[6]);
    }
  }

  if (filtered.length === 0) {
    container.innerHTML = `
      <div class="text-center py-16 px-6 bg-white dark:bg-slate-900 rounded-3xl shadow-sm">
        <div class="w-14 h-14 mx-auto mb-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-2xl flex items-center justify-center text-indigo-500">
          <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4M12 20V4"/>
          </svg>
        </div>
        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100 mb-1">
          ${currentViewMode === 'day' ? 'В этот день пар нет' : 'Занятий по выбранным фильтрам не найдено'}
        </h3>
        <p class="text-xs text-slate-400">
          ${currentViewMode === 'day' ? 'Свободный день или выходной' : 'Попробуйте сбросить фильтры'}
        </p>
      </div>
    `;
    return;
  }

  if (currentViewMode === 'week') {
    const daysMap = {};
    filtered.forEach(it => {
      const dKey = it.дата ? it.дата.split('T')[0] : '';
      if (!daysMap[dKey]) daysMap[dKey] = [];
      daysMap[dKey].push(it);
    });

    let html = '';
    Object.keys(daysMap).sort().forEach(dateKey => {
      const items = daysMap[dateKey].sort((a, b) => (a.начало || '').localeCompare(b.начало || ''));
      const dayCustomTitle = formatCustomDateTitle(items[0]?.дата);

      html += `
        <div class="mb-7">
          <div class="flex items-center justify-between mb-3 pb-1">
            <span class="font-extrabold text-base text-slate-900 dark:text-white">${dayCustomTitle}</span>
          </div>
          <div class="grid grid-cols-1 gap-3">
            ${items.map(it => renderPairCard(it)).join('')}
          </div>
        </div>
      `;
    });
    container.innerHTML = html;
  } else {
    filtered.sort((a, b) => (a.начало || '').localeCompare(b.начало || ''));
    container.innerHTML = `
      <div class="grid grid-cols-1 gap-3.5">
        ${filtered.map(it => renderPairCard(it)).join('')}
      </div>
    `;
  }
}

// -------------------------------------------------------------
// Карточка одной пары со списком задач, редактированием и удалением
// -------------------------------------------------------------
function renderPairCard(item) {
  const disc = parseDiscipline(item.дисциплина);
  const isCurrent = isPairCurrent(item.датаНачала, item.датаОкончания);
  const isSubgroup = item.номерПодгруппы && item.номерПодгруппы > 0;
  const cleanRoom = item.аудитория ? item.аудитория.trim() : '—';
  const pairDate = item.дата ? item.дата.split('T')[0] : selectedDate;
  const pairKey = getPairKey(item);

  // 1. Проверяем наличие автоматической лабы
  const labInfo = disc.typeKey === 'lab' ? getLabInfoForPair(disc.title, pairDate) : null;
  const isDefaultLabDeleted = labInfo && (todoData.deletedDefaults || []).includes(labInfo.todoKey);

  // 2. Ищем добавленные пользователем задачи к этой паре
  const customPairNotes = (todoData.customNotes || []).filter(n => n.pairKey === pairKey);

  // Собираем HTML задач для блока пары
  let taskItemsHtml = '';
  let totalTasksCount = 0;

  // Автоматическая лаба (если не удалена)
  if (labInfo && !isDefaultLabDeleted) {
    totalTasksCount++;
    const isChecked = !!(todoData.checkboxes && todoData.checkboxes[labInfo.todoKey]);
    const labTitle = todoData.editedDefaults?.[labInfo.todoKey] || `TODO: ${labInfo.label}`;

    taskItemsHtml += `
      <div class="flex items-center justify-between group/task py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
        <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
          <input 
            type="checkbox" 
            ${isChecked ? 'checked' : ''} 
            onchange="toggleCheckbox('${labInfo.todoKey}', this.checked)"
            class="w-4 h-4 rounded text-indigo-600 focus:ring-0 accent-indigo-600 cursor-pointer transition shrink-0"
          >
          <span class="text-xs font-semibold truncate ${
            isChecked
              ? 'line-through text-slate-400 dark:text-slate-500' 
              : 'text-slate-700 dark:text-slate-200'
          } transition">
            ${escapeHtml(labTitle)}
          </span>
        </label>
        
        <div class="flex items-center gap-1 shrink-0">
          <span class="text-[10px] font-bold px-1.5 py-0.5 rounded ${
            isChecked 
              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' 
              : 'bg-purple-500/10 text-purple-600 dark:text-purple-400'
          }">
            ${isChecked ? 'Сдано ✓' : 'К сдаче'}
          </span>
          <button 
            type="button"
            onclick="openEditDefaultLabModal('${labInfo.todoKey}', '${escapeHtml(labTitle)}')" 
            title="Переименовать лабу"
            class="p-1 rounded text-slate-400 hover:text-indigo-600 transition"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
          </button>
          <button 
            type="button"
            onclick="deleteDefaultLab('${labInfo.todoKey}')" 
            title="Удалить лабу"
            class="p-1 rounded text-slate-400 hover:text-rose-600 transition"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
        </div>
      </div>
    `;
  }

  // Кастомные задачи пары
  customPairNotes.forEach(n => {
    totalTasksCount++;
    taskItemsHtml += `
      <div class="flex items-center justify-between group/task py-1 px-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/60 transition">
        <label class="flex items-center gap-2.5 cursor-pointer select-none flex-1 min-w-0 pr-2">
          <input 
            type="checkbox" 
            ${n.completed ? 'checked' : ''} 
            onchange="toggleCustomNote('${n.id}', this.checked)"
            class="w-4 h-4 rounded text-indigo-600 focus:ring-0 accent-indigo-600 cursor-pointer transition shrink-0"
          >
          <span class="text-xs font-semibold truncate ${
            n.completed
              ? 'line-through text-slate-400 dark:text-slate-500' 
              : 'text-slate-700 dark:text-slate-200'
          } transition">
            ${escapeHtml(n.text)}
          </span>
        </label>
        
        <div class="flex items-center gap-1 shrink-0">
          <button 
            type="button"
            onclick="openEditCustomNoteModal('${n.id}')" 
            title="Редактировать задачу"
            class="p-1 rounded text-slate-400 hover:text-indigo-600 transition"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
          </button>
          <button 
            type="button"
            onclick="deleteCustomNote('${n.id}')" 
            title="Удалить задачу"
            class="p-1 rounded text-slate-400 hover:text-rose-600 transition"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
        </div>
      </div>
    `;
  });

  // Блок заметок внизу карточки
  let notesSectionHtml = `
    <div class="mt-3 pt-2.5 border-t border-slate-100/70 dark:border-slate-800/60 pl-2">
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
          <span>Заметки и лабы</span>
          ${totalTasksCount > 0 ? `<span class="font-mono text-[10px] px-1.5 py-0.2 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">${totalTasksCount}</span>` : ''}
        </span>
        <button 
          type="button"
          onclick="openAddPairNoteModal('${escapeHtml(pairKey)}', '${pairDate}')" 
          class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-indigo-50 dark:hover:bg-indigo-950/50 transition"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>Добавить</span>
        </button>
      </div>

      ${totalTasksCount > 0 ? `
        <div class="space-y-1 mt-1">
          ${taskItemsHtml}
        </div>
      ` : `
        <div class="py-1">
          <button 
            type="button"
            onclick="openAddPairNoteModal('${escapeHtml(pairKey)}', '${pairDate}')" 
            class="text-xs text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
          >
            + Добавить задачу или тему отчета...
          </button>
        </div>
      `}
    </div>
  `;

  return `
    <article class="schedule-card relative bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 shadow-sm transition-all flex flex-col justify-between overflow-hidden ${
      isCurrent ? 'ring-2 ring-emerald-500' : ''
    }">
      <!-- Полоска типа пары слева -->
      <div class="absolute left-0 top-0 bottom-0 w-1.5 ${
        disc.typeKey === 'lecture' ? 'bg-emerald-500' :
        disc.typeKey === 'lab' ? 'bg-purple-500' :
        disc.typeKey === 'practice' ? 'bg-amber-500' : 'bg-indigo-500'
      }"></div>

      <div>
        <!-- Верхняя строчка: Номер пары, Время, Статус, Тип -->
        <div class="flex items-center justify-between gap-2 mb-2 pl-2">
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-700 dark:text-slate-200">
              ${item.номерЗанятия || '—'}
            </span>
            <span class="font-mono text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200">
              ${item.начало || ''} – ${item.конец || ''}
            </span>
            ${isCurrent ? `
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                Сейчас
              </span>
            ` : ''}
          </div>

          <div class="flex items-center gap-1.5">
            ${isSubgroup ? `
              <span class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                ${item.номерПодгруппы} п/г
              </span>
            ` : ''}
            <span class="px-2.5 py-0.5 rounded-md text-[11px] font-bold ${disc.badgeClass}">
              ${disc.type}
            </span>
          </div>
        </div>

        <!-- Название предмета -->
        <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white leading-snug pl-2">
          ${disc.title}
        </h4>
      </div>

      <!-- Блок Преподавателя и Аудитории -->
      <div class="pt-3 mt-3 border-t border-slate-100/70 dark:border-slate-800/60 pl-2 flex items-center justify-between text-xs text-slate-600 dark:text-slate-300">
        
        <!-- Преподаватель -->
        <div class="flex items-center gap-1.5 truncate pr-3">
          <div class="w-5 h-5 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 text-[10px] font-bold">
            👤
          </div>
          <span class="font-medium text-slate-900 dark:text-slate-200 truncate">
            ${item.преподаватель || item.фиоПреподавателя || '—'}
          </span>
          ${item.должность ? `<span class="opacity-60 text-[11px]">(${item.должность})</span>` : ''}
        </div>

        <!-- Аудитория -->
        <div class="shrink-0 font-mono font-bold text-slate-800 dark:text-slate-200 bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-lg text-xs">
          ${cleanRoom}
        </div>
      </div>

      <!-- В САМОМ КОНЦЕ блока пары: Задачи и заметки с чекбоксами -->
      ${notesSectionHtml}

    </article>
  `;
}

function setTypeFilter(typeKey) {
  activeTypeFilter = typeKey;
  document.querySelectorAll('.type-filter-btn').forEach(btn => {
    if (btn.dataset.type === typeKey) {
      btn.classList.add('bg-indigo-600', 'text-white');
      btn.classList.remove('bg-slate-100', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300');
    } else {
      btn.classList.remove('bg-indigo-600', 'text-white');
      btn.classList.add('bg-slate-100', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300');
    }
  });
  renderScheduleCards();
}

function initTheme() {
  const isDark = localStorage.getItem('theme') === 'dark' || 
    (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (isDark) {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
}

function toggleTheme() {
  const isDark = document.documentElement.classList.toggle('dark');
  localStorage.setItem('theme', isDark ? 'dark' : 'light');
}

window.addEventListener('DOMContentLoaded', async () => {
  initTheme();
  await loadTodoStates();
  initialLoad();
});
