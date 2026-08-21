@echo off
REM ---------------------------------------------------------------
REM Katabatic - atalho de captura
REM
REM   katabatic.bat setup                        instala a dependencia (uma vez so)
REM   katabatic.bat probe                         sonda as SimVars
REM   katabatic.bat KBT118                        grava o voo (so local, sem enviar - como antes)
REM   katabatic.bat KBT118 PAFA PABT carga        grava E envia pro servidor ao final (Ctrl+C)
REM
REM Se o Python estiver num lugar incomum, defina o caminho na linha
REM abaixo (tire o REM da frente e ajuste):
REM set "PY=C:\Caminho\Para\python.exe"
REM ---------------------------------------------------------------
REM
REM Config da ingestao ACARS (MVP) - preencha uma vez e esqueca. Sem
REM isso preenchido, a gravacao continua funcionando exatamente como
REM antes (so CSV local) - katabatic_capture.py so tenta enviar se
REM tiver servidor, token e CID do piloto. Ver README do projeto,
REM "Backend: ingestao ACARS (MVP)".
set "KATABATIC_SERVER="
set "KATABATIC_ACARS_TOKEN="
set "KATABATIC_PILOT_CID="
REM ---------------------------------------------------------------
setlocal enabledelayedexpansion

if defined PY goto :validate

REM 1) Instalacoes reais do python.org - procuradas primeiro de proposito.
REM    O alias da Microsoft Store (WindowsApps\python3.exe) nao e um
REM    interpretador: e um stub que abre a loja e falha ao rodar script.
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

REM 2) python no PATH, descartando qualquer coisa dentro de WindowsApps
for /f "delims=" %%P in ('where python 2^>nul') do (
  echo %%P | find /i "WindowsApps" >nul
  if errorlevel 1 (
    set "PY=%%P"
    goto :validate
  )
)

REM 3) py launcher como ultimo recurso
where py >nul 2>&1 && (set "PY=py" & goto :validate)

echo.
echo Nenhum Python utilizavel encontrado.
echo.
echo Se aparecer algo em WindowsApps, e o alias da Microsoft Store - ele nao roda script.
echo   1. Configuracoes ^> Aplicativos ^> Configuracoes avancadas de aplicativos
echo      ^> Aliases de execucao de aplicativo ^> desligue python.exe e python3.exe
echo   2. Instale de python.org (Windows installer 64-bit)
echo      marcando "Add python.exe to PATH"
echo.
pause
exit /b 1

:validate
REM Confirma que o interpretador realmente executa, e que e 64 bits.
"%PY%" -c "import platform,sys; a=platform.architecture()[0]; print('Python:', sys.executable); print('Arquitetura:', a); sys.exit(0 if a=='64bit' else 2)" 2>nul
if errorlevel 2 (
  echo.
  echo ATENCAO: este Python e 32 bits. O SimConnect exige 64 bits.
  echo Instale a versao "Windows installer 64-bit" do python.org.
  echo.
  pause
  exit /b 1
)
if errorlevel 1 (
  echo.
  echo O Python encontrado nao executou: %PY%
  echo Provavelmente e o stub da Microsoft Store. Veja as instrucoes acima.
  echo.
  pause
  exit /b 1
)

if /i "%~1"=="setup" (
  "%PY%" -m pip install --upgrade pip
  "%PY%" -m pip install SimConnect
  echo.
  echo Pronto. Agora rode:  katabatic.bat probe
  pause
  exit /b 0
)

if /i "%~1"=="probe" (
  "%PY%" "%~dp0katabatic_capture.py" --probe
  pause
  exit /b 0
)

if "%~1"=="" (
  echo.
  echo Uso:
  echo   katabatic.bat setup                     instala a dependencia
  echo   katabatic.bat probe                      sonda as SimVars
  echo   katabatic.bat KBT118                     grava o voo (so local, sem enviar)
  echo   katabatic.bat KBT118 PAFA PABT carga     grava e envia pro servidor ao final
  echo.
  echo O envio so acontece se KATABATIC_SERVER, KATABATIC_ACARS_TOKEN e
  echo KATABATIC_PILOT_CID estiverem preenchidos no topo deste arquivo -
  echo sem isso, se comporta exatamente como antes ^(so grava local^).
  echo.
  pause
  exit /b 0
)

"%PY%" "%~dp0katabatic_capture.py" --record --callsign "%~1" --origem "%~2" --destino "%~3" --tipo "%~4" ^
  --server "%KATABATIC_SERVER%" --token "%KATABATIC_ACARS_TOKEN%" --pilot-cid "%KATABATIC_PILOT_CID%"
pause
