const express = require('express');
const router = express.Router();

/**
 * Health check route
 * Returns status OK with timestamp
 */
router.get('/health', (req, res) => {
  const response = {
    status: 'OK',
    timestamp: new Date().toISOString()
  };
  
  res.status(200).json(response);
});

module.exports = router;