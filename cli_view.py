#!/usr/bin/env python3
"""
Консольный просмотрщик расписания КГЭУ.
Умеет загружать актуальные данные напрямую с API kabinet.kgeu.ru.
Запуск: python3 cli_view.py
"""

import json
import urllib.request
from pathlib import Path
from datetime import datetime

DEFAULT_ID_GROUP = 14493
DEFAULT_DATE = "2026-10-09"
API_URL = f"https://kabinet.kgeu.ru/api/Rasp?idGroup={DEFAULT_ID_GROUP}&sdate={DEFAULT_DATE}"
DATA_FILE = Path(__file__).parent / "data" / "schedule_sample.json"

def clean_discipline(name: str):
    prefix_map = {
        "лек": ("Лекция", "\033[92m"),       # Зеленый
        "лаб": ("Лабораторная", "\033[95m"), # Фиолетовый
        "пр":  ("Практика", "\033[93m"),     # Желтый
        "зач": ("Зачет", "\033[91m"),        # Красный
        "экз": ("Экзамен", "\033[91m")       # Красный
    }
    for prefix, (type_name, color) in prefix_map.items():
        if name.startswith(prefix + " "):
            return type_name, name[len(prefix) + 1:].strip(), color
    return "Занятие", name, "\033[94m"

def load_data():
    try:
        req = urllib.request.Request(
            API_URL,
            headers={
                "User-Agent": "Mozilla/5.0",
                "Accept": "application/json"
            }
        )
        with urllib.request.urlopen(req, timeout=5) as resp:
            if resp.status == 200:
                return json.loads(resp.read().decode())
    except Exception:
        pass

    if DATA_FILE.exists():
        with open(DATA_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return None

def main():
    payload = load_data()
    if not payload:
        print("Ошибка загрузки данных.")
        return

    data = payload.get("data", {})
    rasp = data.get("rasp", [])
    info = data.get("info", {})

    group = info.get("group", {}).get("name", "ПМ-1-24")
    year = info.get("year", "2026-2027")
    week = info.get("curWeekNumber", "?")
    num_ned = "Четная" if info.get("curNumNed") == 2 else "Нечетная"

    print("\033[1m" + "=" * 65 + "\033[0m")
    print(f"\033[1;36m🎓 РАСПИСАНИЕ КГЭУ | Группа: {group} | {year} уч. год\033[0m")
    print(f"📅 Неделя {week} ({num_ned}) | Всего пар в сетке: {len(rasp)}")
    print("\033[1m" + "=" * 65 + "\033[0m\n")

    # Группировка по дням
    days = {}
    for item in rasp:
        d_key = item.get("дата", "").split("T")[0]
        days.setdefault(d_key, []).append(item)

    for date_str, items in days.items():
        day_name = items[0].get("день_недели", "")
        formatted_date = ""
        try:
            dt = datetime.strptime(date_str, "%Y-%m-%d")
            formatted_date = dt.strftime("%d.%m.%Y")
        except Exception:
            formatted_date = date_str

        print(f"\033[1;33m📌 {day_name.upper()} ({formatted_date})\033[0m")
        print("-" * 65)

        for it in sorted(items, key=lambda x: x.get("начало", "")):
            time_range = f"{it.get('начало', '??:??')} - {it.get('конец', '??:??')}"
            num = it.get("номерЗанятия", "-")
            type_name, title, color = clean_discipline(it.get("дисциплина", ""))
            teacher = it.get("преподаватель") or it.get("фиоПреподавателя", "—")
            post = f"({it.get('должность')})" if it.get("должность") else ""
            room = it.get("аудитория", "—").strip()

            reset = "\033[0m"
            print(f"  \033[1m[{num} пара]\033[0m  {time_range}  {color}[{type_name:^12}]{reset} \033[1m{title}\033[0m")
            print(f"           Аудитория: \033[36;1m{room}\033[0m | Преподаватель: {teacher} {post}")
            print()

        print()

if __name__ == "__main__":
    main()
