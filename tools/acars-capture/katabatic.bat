@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ===============================================================
REM Katabatic - atalho de captura
REM
REM Uso:
REM   katabatic.bat setup
REM   katabatic.bat probe
REM   katabatic.bat KBT118
REM   katabatic.bat KBT118 PAFA PABT carga
REM ===============================================================

REM ===============================================================
REM CONFIGURACAO ACARS
REM ===============================================================
set "KATABATIC_SERVER=http://localhost:8000"
set "KATABATIC_ACARS_TOKEN=1233523SDAS2312ASD"
set "KATABATIC_PILOT_CID=1234567"
REM Segundos entre POSTs de posicao pro Mapa ao vivo (ACARS fase 3) - 0
REM desliga so o heartbeat, sem desligar o inicio/fechamento do voo.
set "KATABATIC_ACARS_POS_INTERVAL=12"

REM ===============================================================
REM GUARDA OS ARGUMENTOS ANTES DE QUALQUER GOTO
REM ===============================================================
set "ARG1=%~1"
set "ARG2=%~2"
set "ARG3=%~3"
set "ARG4=%~4"

REM ===============================================================
REM ENCONTRA PYTHON
REM ===============================================================

if defined PY goto :validate

for %%V in (313 312 311 310 39) do (
    if exist "%LOCALAPPDATA%\Programs\Python\Python%%V\python.exe" (
        set "PY=%LOCALAPPDATA%\Programs\Python\Python%%V\python.exe"
        goto :validate
    )

    if exist "C:\Program Files\Python%%V\python.exe" (
        set "PY=C:\Program Files\Python%%V\python.exe"
        goto :validate
    )

    if exist "C:\Python%%V\python.exe" (
        set "PY=C:\Python%%V\python.exe"
        goto :validate
    )
)

for /f "delims=" %%P in ('where python 2^>nul') do (
    echo %%P | find /i "WindowsApps" >nul
    if errorlevel 1 (
        set "PY=%%P"
        goto :validate
    )
)

where py >nul 2>&1
if not errorlevel 1 (
    set "PY=py"
    goto :validate
)

echo.
echo Nenhum Python utilizavel encontrado.
echo.
pause
exit /b 1


:validate

echo Python: %PY%

"%PY%" -c "import platform,sys; a=platform.architecture()[0]; print('Arquitetura:', a); sys.exit(0 if a=='64bit' else 2)" 2>nul

if errorlevel 2 (
    echo.
    echo ATENCAO: este Python e 32 bits.
    echo O SimConnect exige Python 64 bits.
    echo.
    pause
    exit /b 1
)

if errorlevel 1 (
    echo.
    echo O Python encontrado nao executou: %PY%
    echo.
    pause
    exit /b 1
)

REM ===============================================================
REM COMANDOS ESPECIAIS
REM ===============================================================

if /i "%ARG1%"=="setup" (
    "%PY%" -m pip install --upgrade pip
    "%PY%" -m pip install SimConnect
    echo.
    echo Pronto.
    echo.
    pause
    exit /b 0
)

if /i "%ARG1%"=="probe" (
    "%PY%" "%~dp0katabatic_capture.py" --probe
    pause
    exit /b 0
)

REM ===============================================================
REM SEM ARGUMENTOS
REM ===============================================================

if "%ARG1%"=="" (
    echo.
    echo Uso:
    echo   katabatic.bat setup
    echo   katabatic.bat probe
    echo   katabatic.bat KBT118
    echo   katabatic.bat KBT118 PAFA PABT carga
    echo.
    echo Envio ao servidor:
    echo   Server: %KATABATIC_SERVER%
    echo   Pilot CID: %KATABATIC_PILOT_CID%
    echo   Token: configurado
    echo.
    pause
    exit /b 0
)

REM ===============================================================
REM DEBUG
REM ===============================================================

echo.
echo Callsign : %ARG1%
echo Origem   : %ARG2%
echo Destino  : %ARG3%
echo Tipo     : %ARG4%
echo Server   : %KATABATIC_SERVER%
echo Pilot CID: %KATABATIC_PILOT_CID%
echo.

REM ===============================================================
REM EXECUTA KATABATIC
REM ===============================================================

"%PY%" "%~dp0katabatic_capture.py" ^
    --record ^
    --callsign "%ARG1%" ^
    --origem "%ARG2%" ^
    --destino "%ARG3%" ^
    --tipo "%ARG4%" ^
    --server "%KATABATIC_SERVER%" ^
    --token "%KATABATIC_ACARS_TOKEN%" ^
    --pilot-cid "%KATABATIC_PILOT_CID%" ^
    --pos-interval "%KATABATIC_ACARS_POS_INTERVAL%"

echo.
pause