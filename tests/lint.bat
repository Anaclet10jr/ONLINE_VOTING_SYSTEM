@echo off
for /r "C:\xampp\htdocs\VOTING_SYSTEM" %%f in (*.php) do "C:\xampp\php\php.exe" -l "%%f"
