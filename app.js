/**
 * ==============================================================================
 * КГЭУ Расписание — Клиентская часть на Vue.js 3
 * Объединённое расписание групп: ПМ-1-24 и ЦК-ИЦС-3
 * Цветовая дифференциация по типам: Лекция (зеленый), Лаба (фиолетовый), Практика (янтарный)
 * Количество точек в календаре = количеству пар в дне
 * Фильтрация строго по группам (Все / ПМ-1-24 / ЦК-ИЦС-3)
 * ==============================================================================
 */

const { createApp, ref, computed, onMounted } = Vue;

// ------------------------------------------------------------------------------
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ И УТИЛИТЫ ДАТ И СТРОК
// ------------------------------------------------------------------------------

/** Текущая дата в формате YYYY-MM-DD */
function getTodayDateStr() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Форматирование заголовка дня («Среда — 18.11.2026») */
function formatFullDayTitle(dateStr) {
  if (!dateStr) return '';
  const days = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
  const dt = new Date(dateStr);
  const dayName = days[dt.getDay()];
  const dd = String(dt.getDate()).padStart(2, '0');
  const mm = String(dt.getMonth() + 1).padStart(2, '0');
  const yyyy = dt.getFullYear();
  return `${dayName} — ${dd}.${mm}.${yyyy}`;
}

/**
 * Парсер строки дисциплины из API КГЭУ
 * Возвращает тип пары (Лекция, Лабораторная, Практика), цвета бейджа, полоски и точки
 */
function parseDiscipline(rawName) {
  if (!rawName) {
    return { 
      type: 'Занятие', 
      typeKey: 'other', 
      title: '—', 
      badgeClass: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
      stripeClass: 'bg-indigo-500',
      dotClass: 'bg-indigo-500'
    };
  }
  const trimmed = String(rawName).trim();
  if (trimmed.startsWith('лек ')) {
    return {
      type: 'Лекция',
      typeKey: 'lecture',
      title: trimmed.replace(/^лек\s+/, ''),
      badgeClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
      stripeClass: 'bg-emerald-500',
      dotClass: 'bg-emerald-500'
    };
  } else if (trimmed.startsWith('лаб ')) {
    return {
      type: 'Лабораторная',
      typeKey: 'lab',
      title: trimmed.replace(/^лаб\s+/, ''),
      badgeClass: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20',
      stripeClass: 'bg-purple-500',
      dotClass: 'bg-purple-500'
    };
  } else if (trimmed.startsWith('пр ')) {
    return {
      type: 'Практика',
      typeKey: 'practice',
      title: trimmed.replace(/^пр\s+/, ''),
      badgeClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20',
      stripeClass: 'bg-amber-500',
      dotClass: 'bg-amber-500'
    };
  } else if (trimmed.startsWith('зач ')) {
    return {
      type: 'Зачет',
      typeKey: 'exam',
      title: trimmed.replace(/^зач\s+/, ''),
      badgeClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20',
      stripeClass: 'bg-rose-500',
      dotClass: 'bg-rose-500'
    };
  } else if (trimmed.startsWith('экз ')) {
    return {
      type: 'Экзамен',
      typeKey: 'exam',
      title: trimmed.replace(/^экз\s+/, ''),
      badgeClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20',
      stripeClass: 'bg-rose-500',
      dotClass: 'bg-rose-500'
    };
  }
  return {
    type: 'Занятие',
    typeKey: 'other',
    title: trimmed,
    badgeClass: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20',
    stripeClass: 'bg-indigo-500',
    dotClass: 'bg-indigo-500'
  };
}

/** Уникальный ключ для идентификации пары в расписании */
function getPairKey(item) {
  if (!item) return '';
  const g = item.groupId || item.groupName || '';
  const dateStr = item.дата ? item.дата.split('T')[0] : '';
  const num = item.номерЗанятия || '';
  const time = (item.начало || '').trim();
  const sub = item.номерПодгруппы || 0;
  const disc = (item.дисциплина || '').trim().toLowerCase();
  return `${g}_${dateStr}_${num}_${time}_${sub}_${disc}`;
}

/** Проверяет, идет ли пара прямо сейчас */
function isPairCurrent(startIso, endIso) {
  if (!startIso || !endIso) return false;
  const now = new Date();
  const start = new Date(startIso);
  const end = new Date(endIso);
  return now >= start && now <= end;
}

// ------------------------------------------------------------------------------
// ОСНОВНОЕ ПРИЛОЖЕНИЕ VUE 3
// ------------------------------------------------------------------------------
createApp({
  setup() {
    // --------------------------------------------------------------------------
    // 1. РЕАКТИВНОЕ СОСТОЯНИЕ (STATE)
    // --------------------------------------------------------------------------

    // Активный фильтр по группе: 'all' (все группы) | '14493' (ПМ-1-24) | '15006' (ЦК-ИЦС-3)
    const activeGroupFilter = ref('all');

    // Выбранная дата в формате YYYY-MM-DD (по умолчанию — сегодня)
    const selectedDate = ref(getTodayDateStr());

    // Месяц для левого календаря (объект Date)
    const calendarMonth = ref(new Date());

    // База пар расписания, сгруппированная по датам: { 'YYYY-MM-DD': [пара1, пара2] }
    const allPairsMap = ref({});

    // Статус синхронизации и время последнего обновления
    const isSyncing = ref(false);
    const syncTimeText = ref('Загрузка...');

    // Флаг тёмной темы оформления
    const isDark = ref(false);

    // --------------------------------------------------------------------------
    // 2. ВЫЧИСЛЯЕМЫЕ СВОЙСТВА (COMPUTED)
    // --------------------------------------------------------------------------

    // Название текущего месяца для шапки календаря (например: «Ноябрь 2026»)
    const calendarMonthTitle = computed(() => {
      return calendarMonth.value.toLocaleDateString('ru-RU', {
        month: 'long',
        year: 'numeric'
      }).replace(' г.', '');
    });

    // Заголовок текущего дня без обрезания в "..." (например: «Среда — 18.11.2026»)
    const currentViewTitle = computed(() => {
      return formatFullDayTitle(selectedDate.value);
    });

    // Генерация сетки дней для календаря:
    // Количество точек = строго количеству пар в этот день!
    // Цвет каждой точки = типу занятия (зеленая - лекция, фиолетовая - лаба, янтарная - практика)
    const calendarDays = computed(() => {
      const year = calendarMonth.value.getFullYear();
      const month = calendarMonth.value.getMonth();

      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0);

      let startDayOfWeek = firstDay.getDay(); // 0 (Вс) .. 6 (Сб)
      if (startDayOfWeek === 0) startDayOfWeek = 7; // Делаем 1 (Пн) .. 7 (Вс)

      const totalDays = lastDay.getDate();
      const realToday = getTodayDateStr();

      const days = [];

      // Пустые ячейки в начале сетки до 1-го числа
      for (let i = 1; i < startDayOfWeek; i++) {
        days.push({ isEmpty: true, key: `empty-${i}` });
      }

      // Числа месяца
      for (let d = 1; d <= totalDays; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        let pairs = allPairsMap.value[dateStr] || [];

        // Если включен фильтр конкретной группы — фильтруем пары и точки в календаре
        if (activeGroupFilter.value !== 'all') {
          pairs = pairs.filter(p => String(p.groupId) === String(activeGroupFilter.value));
        }

        // Дедупликация одинаковых пар
        const unique = new Map();
        pairs.forEach(p => {
          const k = getPairKey(p);
          if (!unique.has(k)) unique.set(k, p);
        });
        const dayPairs = Array.from(unique.values());
        dayPairs.sort((a, b) => (a.начало || '').localeCompare(b.начало || ''));

        // Каждая точка соответствует одной конкретной паре в этот день
        const dots = dayPairs.map(p => {
          const disc = parseDiscipline(p.дисциплина);
          return {
            type: disc.type,
            colorClass: disc.dotClass
          };
        });

        days.push({
          isEmpty: false,
          dayNumber: d,
          dateStr: dateStr,
          isSelected: selectedDate.value === dateStr,
          isToday: dateStr === realToday,
          dots
        });
      }

      return days;
    });

    // Список пар, отфильтрованный по выбранной дате и группе
    const displayedPairs = computed(() => {
      let rawPairs = allPairsMap.value[selectedDate.value] || [];

      // Фильтрация по группе (ПМ-1-24 или ЦК-ИЦС-3)
      if (activeGroupFilter.value !== 'all') {
        rawPairs = rawPairs.filter(p => String(p.groupId) === String(activeGroupFilter.value));
      }

      // Дедупликация одинаковых записей пар
      const unique = new Map();
      rawPairs.forEach(p => {
        const k = getPairKey(p);
        if (!unique.has(k)) unique.set(k, p);
      });
      let list = Array.from(unique.values());

      // Сортировка по времени начала
      list.sort((a, b) => {
        const dComp = (a.дата || '').localeCompare(b.дата || '');
        if (dComp !== 0) return dComp;
        return (a.начало || '').localeCompare(b.начало || '');
      });

      return list;
    });

    // --------------------------------------------------------------------------
    // 3. МЕТОДЫ (МГНОВЕННЫЕ ДЕЙСТВИЯ БЕЗ ПЕРЕЗАГРУЗОК)
    // --------------------------------------------------------------------------

    // Выбор дня в календаре
    function selectCalendarDate(dateStr) {
      selectedDate.value = dateStr;
    }

    // Предыдущий месяц в календаре
    function prevMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1
      );
    }

    // Следующий месяц в календаре
    function nextMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1
      );
    }

    // Возврат календаря на сегодняшний день
    function resetToToday() {
      const todayStr = getTodayDateStr();
      selectedDate.value = todayStr;
      calendarMonth.value = new Date();
    }

    // Переключение активной группы (Все / ПМ-1-24 / ЦК-ИЦС-3)
    function selectGroupFilter(groupId) {
      activeGroupFilter.value = String(groupId);
    }

    // Переключение темы (светлая / тёмная)
    function toggleTheme() {
      isDark.value = !isDark.value;
      if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
      } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
      }
    }

    // --------------------------------------------------------------------------
    // 4. СВЯЗЬ С PHP-БЭКЕНДОМ ЧЕРЕЗ API
    // --------------------------------------------------------------------------

    // Загрузка расписания обеих групп
    async function loadSchedule() {
      isSyncing.value = true;

      // Быстрое чтение локальных кэшей
      try {
        const [c14493, c15006] = await Promise.allSettled([
          fetch('./data/schedule_semester_14493.json').then(r => r.ok ? r.json() : null),
          fetch('./data/schedule_semester_15006.json').then(r => r.ok ? r.json() : null)
        ]);
        const cachedPairs = [];
        if (c14493.status === 'fulfilled' && c14493.value?.data?.rasp) {
          c14493.value.data.rasp.forEach(p => {
            p.groupId = 14493;
            p.groupName = 'ПМ-1-24';
            cachedPairs.push(p);
          });
        }
        if (c15006.status === 'fulfilled' && c15006.value?.data?.rasp) {
          c15006.value.data.rasp.forEach(p => {
            p.groupId = 15006;
            p.groupName = 'ЦК-ИЦС-3';
            cachedPairs.push(p);
          });
        }
        if (cachedPairs.length > 0) {
          applyScheduleItems(cachedPairs);
        }
      } catch (e) {
        console.warn('Локальный кэш:', e);
      }

      // Получаем объединенные данные от PHP сервера (/api/full-schedule)
      try {
        const res = await fetch('/api/full-schedule?idGroup=all');
        if (res.ok) {
          const fullData = await res.json();
          if (fullData?.data?.rasp) {
            applyScheduleItems(fullData.data.rasp);
          }
          if (fullData?.data?.cachedAt) {
            const cachedDate = new Date(fullData.data.cachedAt);
            syncTimeText.value = `Обновлено в ${cachedDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
          }
        }
      } catch (e) {
        console.warn('Сетевой запрос расписания:', e);
      } finally {
        isSyncing.value = false;
        if (!syncTimeText.value || syncTimeText.value === 'Загрузка...') {
          const now = new Date();
          syncTimeText.value = `Обновлено в ${now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
        }
      }
    }

    // Ручное принудительное обновление расписания
    async function manualRefresh() {
      isSyncing.value = true;
      try {
        const res = await fetch('/api/full-schedule?idGroup=all&refresh=1');
        if (res.ok) {
          const data = await res.json();
          if (data?.data?.rasp) {
            applyScheduleItems(data.data.rasp);
          }
          if (data?.data?.cachedAt) {
            const cachedDate = new Date(data.data.cachedAt);
            syncTimeText.value = `Обновлено в ${cachedDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
          }
        }
      } catch (e) {
        console.warn('Ошибка принудительного обновления:', e);
      } finally {
        isSyncing.value = false;
        if (!syncTimeText.value || syncTimeText.value === 'Загрузка...') {
          const now = new Date();
          syncTimeText.value = `Обновлено в ${now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
        }
      }
    }

    // Раскладываем пары по датам
    function applyScheduleItems(items) {
      if (!Array.isArray(items)) return;
      const map = {};
      items.forEach(it => {
        if (!it.дата) return;
        const d = it.дата.split('T')[0];
        if (!map[d]) map[d] = [];
        map[d].push(it);
      });
      allPairsMap.value = map;
    }

    // --------------------------------------------------------------------------
    // 5. ИНИЦИАЛИЗАЦИЯ ПРИ ЗАПУСКЕ (MOUNTED)
    // --------------------------------------------------------------------------
    onMounted(() => {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
      } else {
        isDark.value = false;
        document.documentElement.classList.remove('dark');
      }

      loadSchedule();
    });

    // --------------------------------------------------------------------------
    // 6. ВОЗВРАТ ПЕРЕМЕННЫХ И МЕТОДОВ В ШАБЛОН HTML
    // --------------------------------------------------------------------------
    return {
      activeGroupFilter,
      selectedDate,
      calendarMonth,
      calendarMonthTitle,
      calendarDays,
      currentViewTitle,
      displayedPairs,
      isSyncing,
      syncTimeText,
      isDark,
      selectCalendarDate,
      prevMonth,
      nextMonth,
      resetToToday,
      selectGroupFilter,
      toggleTheme,
      manualRefresh,
      parseDiscipline,
      isPairCurrent,
      getPairKey
    };
  }
}).mount('#app');
