@echo off  & cd /d "%~dp0"  & echo Запуск master API...  REM переход в корень проекта и сообщение
if exist ".venv\Scripts\activate.bat" (  REM проверка наличия виртуального окружения
    call ".venv\Scripts\activate.bat"  REM активация venv
) else (
    echo Внимание: виртуальное окружение .venv не найдено, запуск без него...  REM предупреждение
)
cd master\api  REM переходим в каталог API
python main.py  REM запуск Python-сервера


