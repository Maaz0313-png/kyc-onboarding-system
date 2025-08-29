#!/bin/bash

# KYC System Test Runner
# This script runs comprehensive tests for the KYC onboarding system

echo "🚀 KYC System Test Runner"
echo "========================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP is not installed or not in PATH${NC}"
    exit 1
fi

# Check if Laravel artisan is available
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ Laravel artisan not found. Make sure you're in the project root directory.${NC}"
    exit 1
fi

echo -e "${BLUE}📋 Pre-flight Checks${NC}"
echo "==================="

# Check environment file
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}⚠️  .env file not found. Copying from .env.example${NC}"
    cp .env.example .env
fi

# Check if database exists
if [ ! -f "database/database.sqlite" ]; then
    echo -e "${YELLOW}⚠️  Database not found. Creating SQLite database${NC}"
    touch database/database.sqlite
fi

# Run Laravel migrations
echo -e "${BLUE}🔧 Running Database Migrations${NC}"
php artisan migrate --force

# Run seeders
echo -e "${BLUE}🌱 Running Database Seeders${NC}"
php artisan db:seed --force

# Generate application key if not exists
if ! grep -q "APP_KEY=" .env || [ -z "$(grep APP_KEY= .env | cut -d'=' -f2)" ]; then
    echo -e "${BLUE}🔑 Generating Application Key${NC}"
    php artisan key:generate --force
fi

# Install Passport keys
echo -e "${BLUE}🔐 Installing Passport Keys${NC}"
php artisan passport:install --force

# Clear caches
echo -e "${BLUE}🧹 Clearing Caches${NC}"
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Start Laravel development server in background
echo -e "${BLUE}🌐 Starting Laravel Development Server${NC}"
php artisan serve --host=0.0.0.0 --port=8000 &
SERVER_PID=$!

# Wait for server to start
echo "⏳ Waiting for server to start..."
sleep 5

# Check if server is running
if ! curl -s http://0.0.0.0:8000/api/health > /dev/null; then
    echo -e "${YELLOW}⚠️  Server might not be ready. Waiting a bit more...${NC}"
    sleep 5
fi

echo -e "${GREEN}✅ Server is running on http://0.0.0.0:8000${NC}"

# Run the comprehensive API tests
echo -e "${BLUE}🧪 Running Comprehensive API Tests${NC}"
echo "=================================="
php test_kyc_api.php

TEST_RESULT=$?

# Run PHPUnit tests if available
if [ -f "phpunit.xml" ]; then
    echo -e "${BLUE}🧪 Running PHPUnit Tests${NC}"
    echo "======================="
    ./vendor/bin/phpunit --testdox
    PHPUNIT_RESULT=$?
else
    echo -e "${YELLOW}⚠️  PHPUnit configuration not found. Skipping unit tests.${NC}"
    PHPUNIT_RESULT=0
fi

# Stop the Laravel server
echo -e "${BLUE}🛑 Stopping Laravel Development Server${NC}"
kill $SERVER_PID 2>/dev/null

# Final results
echo ""
echo "📊 FINAL TEST RESULTS"
echo "===================="

if [ $TEST_RESULT -eq 0 ]; then
    echo -e "${GREEN}✅ API Tests: PASSED${NC}"
else
    echo -e "${RED}❌ API Tests: FAILED${NC}"
fi

if [ $PHPUNIT_RESULT -eq 0 ]; then
    echo -e "${GREEN}✅ Unit Tests: PASSED${NC}"
else
    echo -e "${RED}❌ Unit Tests: FAILED${NC}"
fi

# Overall result
if [ $TEST_RESULT -eq 0 ] && [ $PHPUNIT_RESULT -eq 0 ]; then
    echo -e "${GREEN}🎉 ALL TESTS PASSED!${NC}"
    echo -e "${GREEN}✅ KYC System is ready for deployment${NC}"
    exit 0
else
    echo -e "${RED}❌ SOME TESTS FAILED${NC}"
    echo -e "${RED}🔧 Please fix the issues before deployment${NC}"
    exit 1
fi